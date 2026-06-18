<?php

declare(strict_types=1);

namespace Restlytics\Laravel;

use Restlytics\Laravel\Otlp\Payload;
use Restlytics\Laravel\Support\Ids;
use Restlytics\Laravel\Support\Intervals;
use Restlytics\Laravel\Transport\Transport;

/**
 * Per-request tracer: holds the active trace id, the root SERVER span, and the
 * in-request span buffer. Owns the sampling decision and, on finish, computes the
 * self-time rollups and flushes the OTLP batch through the transport.
 *
 * Octane note: this is registered as a singleton, so the SAME instance is reused
 * across requests in a long-lived worker. `reset()` MUST be called at the start
 * of every request to avoid leaking spans (and the trace id) between requests.
 *
 * Timing model: we use hrtime(true) (monotonic) for DURATIONS — it isn't affected
 * by NTP/clock adjustments — and anchor it to a single wall-clock reading so we can
 * emit absolute epoch-nanosecond timestamps. Each span's absolute start is
 * wallAnchorNs + (monoNow - monoAnchor).
 */
final class Tracer
{
    private bool $enabled = false;
    private bool $sampled = false;

    private string $traceId = '';
    private ?string $rootParentSpanId = null;
    private ?Span $rootSpan = null;

    /** @var list<Span> child spans (db/http/cache/...) buffered for this request */
    private array $spans = [];

    /** Wall-clock epoch nanoseconds captured at the anchor point. */
    private int $wallAnchorNs = 0;
    /** Monotonic reading (hrtime) captured at the same anchor point. */
    private int $monoAnchorNs = 0;

    /** Count of DB spans seen this request (for restlytics.db_query_count). */
    private int $dbQueryCount = 0;

    public function __construct(
        private readonly Transport $transport,
        private readonly string $serviceName,
        private readonly string $environment,
        private readonly float $sampleRate = 1.0,
        /** Hard cap on buffered spans to bound memory under pathological traces. */
        private readonly int $maxSpans = 2000,
    ) {
    }

    /**
     * Reset all per-request state. Called at request start (middleware handle and
     * Octane request-received hook) so a reused singleton never leaks across requests.
     */
    public function reset(): void
    {
        $this->enabled = false;
        $this->sampled = false;
        $this->traceId = '';
        $this->rootParentSpanId = null;
        $this->rootSpan = null;
        $this->spans = [];
        $this->wallAnchorNs = 0;
        $this->monoAnchorNs = 0;
        $this->dbQueryCount = 0;
    }

    public function isSampled(): bool
    {
        return $this->enabled && $this->sampled;
    }

    public function traceId(): string
    {
        return $this->traceId;
    }

    public function rootSpanId(): ?string
    {
        return $this->rootSpan?->spanId;
    }

    /**
     * Open the root SERVER span at request start.
     *
     * Continues an incoming W3C traceparent if present (distributed tracing),
     * otherwise mints a fresh trace id. The sampling decision is HEAD-BASED and
     * made exactly once here, keyed off the trace id, so all spans in a trace share
     * the same fate (and a continued trace inherits the upstream sampled flag).
     */
    public function startServerSpan(string $name, ?string $traceparent = null): void
    {
        $this->reset();
        $this->enabled = true;

        $incoming = Ids::parseTraceparent($traceparent);
        if ($incoming !== null) {
            $this->traceId = $incoming['traceId'];
            $this->rootParentSpanId = $incoming['parentSpanId'];
            // Respect an upstream "not sampled" decision; only re-roll if it was sampled.
            $this->sampled = $incoming['sampled'] && $this->sampleDecision($this->traceId);
        } else {
            $this->traceId = Ids::traceId();
            $this->rootParentSpanId = null;
            $this->sampled = $this->sampleDecision($this->traceId);
        }

        // Anchor wall-clock ↔ monotonic clocks together.
        $this->wallAnchorNs = self::wallClockNs();
        $this->monoAnchorNs = hrtime(true);

        if (! $this->sampled) {
            return; // not sampled: stay cheap, record nothing
        }

        $this->rootSpan = new Span(
            traceId: $this->traceId,
            spanId: Ids::spanId(),
            parentSpanId: $this->rootParentSpanId,
            name: $name,
            kind: Span::KIND_SERVER,
            startUnixNano: $this->nowNs(),
            endUnixNano: $this->nowNs(),
        );
    }

    public function rootSpan(): ?Span
    {
        return $this->rootSpan;
    }

    /**
     * Create a CLIENT child span over an absolute [startNs, endNs] window.
     *
     * DB/HTTP/cache instrumentation often only learns of a span AFTER it finished
     * (e.g. QueryExecuted reports elapsed time), so callers back-date the start.
     * Returns null when not sampled or when the buffer cap is hit (telemetry must
     * never grow unbounded).
     */
    public function addChildSpan(string $name, int $startNs, int $endNs, int $kind = Span::KIND_CLIENT): ?Span
    {
        if (! $this->isSampled() || $this->rootSpan === null) {
            return null;
        }
        if (count($this->spans) >= $this->maxSpans) {
            return null;
        }

        $span = new Span(
            traceId: $this->traceId,
            spanId: Ids::spanId(),
            parentSpanId: $this->rootSpan->spanId,
            name: $name,
            kind: $kind,
            startUnixNano: $startNs,
            endUnixNano: $endNs,
        );
        $this->spans[] = $span;

        return $span;
    }

    public function incrementDbQueryCount(): void
    {
        $this->dbQueryCount++;
    }

    /**
     * Close the root span, compute self-time rollups, and flush the batch.
     *
     * Self-time = interval-union of child spans per category (db/http/cache), and
     * app = root duration − union(ALL children). We attach these to the root SERVER
     * span as restlytics.self_ns.* so the dashboard's time breakdown is correct even
     * when children overlap.
     */
    public function finishServerSpan(): void
    {
        if (! $this->isSampled() || $this->rootSpan === null) {
            $this->reset();

            return;
        }

        $this->rootSpan->setEnd($this->nowNs());

        $this->attachSelfTime();
        $this->rootSpan->setInt('restlytics.db_query_count', $this->dbQueryCount);
        $this->rootSpan->setString('restlytics.category', 'app');

        $this->flush();
        $this->reset();
    }

    /**
     * Build the OTLP payload and hand it to the transport (fire-and-forget).
     * Resilient: any failure is swallowed so flushing telemetry can't break the app.
     */
    public function flush(): void
    {
        if ($this->rootSpan === null) {
            return;
        }

        try {
            $all = array_merge([$this->rootSpan], $this->spans);
            $payload = Payload::build($this->serviceName, $this->environment, $all);
            $this->transport->send($payload);
        } catch (\Throwable) {
            // Telemetry must never throw into the host application.
        }
    }

    /**
     * Absolute current time in epoch nanoseconds, derived from the monotonic clock
     * so durations are immune to wall-clock jumps mid-request.
     */
    public function nowNs(): int
    {
        return $this->wallAnchorNs + (hrtime(true) - $this->monoAnchorNs);
    }

    /**
     * Compute and attach restlytics.self_ns.{db,http,cache,app} to the root span.
     */
    private function attachSelfTime(): void
    {
        $rootSpan = $this->rootSpan;
        if ($rootSpan === null) {
            return;
        }

        $rootStart = $rootSpan->startUnixNano;
        $rootDur = $rootSpan->durationNs();

        /** @var array<string, list<array{0:int,1:int}>> $byCat */
        $byCat = ['db' => [], 'http' => [], 'cache' => [], 'app' => []];
        /** @var list<array{0:int,1:int}> $all */
        $all = [];

        foreach ($this->spans as $s) {
            // Normalize to offsets from root start; clamp inverted intervals (skew).
            $start = $s->startUnixNano - $rootStart;
            $end = $s->endUnixNano - $rootStart;
            if ($end < $start) {
                $end = $start;
            }
            $all[] = [$start, $end];

            $cat = $this->categoryOf($s);
            $byCat[$cat][] = [$start, $end];
        }

        $selfDb = Intervals::unionLength($byCat['db']);
        $selfHttp = Intervals::unionLength($byCat['http']);
        $selfCache = Intervals::unionLength($byCat['cache']);
        // app self-time = explicit app-category child time + the root's own
        // exclusive (uncovered) time. Mirrors the ingestion service's computation.
        $selfApp = Intervals::unionLength($byCat['app']) + max(0, $rootDur - Intervals::unionLength($all));

        $rootSpan->setInt('restlytics.self_ns.db', $selfDb);
        $rootSpan->setInt('restlytics.self_ns.http', $selfHttp);
        $rootSpan->setInt('restlytics.self_ns.cache', $selfCache);
        $rootSpan->setInt('restlytics.self_ns.app', $selfApp);
    }

    /**
     * Read a span's restlytics.category attribute for self-time bucketing.
     * Falls back to 'app' so an uncategorized child still contributes sensibly.
     */
    private function categoryOf(Span $span): string
    {
        $otlp = $span->toOtlpArray();
        foreach ($otlp['attributes'] ?? [] as $kv) {
            if (($kv['key'] ?? null) === 'restlytics.category') {
                $cat = $kv['value']['stringValue'] ?? null;
                if (\in_array($cat, ['db', 'http', 'cache', 'app'], true)) {
                    return $cat;
                }
            }
        }

        return 'app';
    }

    /**
     * Head-based trace-id-ratio sampling. Deterministic in the trace id so the
     * decision is stable and unbiased: hash the id into [0,1) and keep it if it
     * falls under the configured rate.
     */
    private function sampleDecision(string $traceId): bool
    {
        if ($this->sampleRate >= 1.0) {
            return true;
        }
        if ($this->sampleRate <= 0.0) {
            return false;
        }

        // Use the last 8 hex chars (32 bits) as the entropy source.
        $tail = substr($traceId, -8);
        $bucket = hexdec($tail === '' ? '0' : $tail); // 0 .. 2^32-1
        $ratio = $bucket / 0xFFFFFFFF;

        return $ratio < $this->sampleRate;
    }

    /** Wall-clock epoch nanoseconds from microtime (microsecond resolution). */
    private static function wallClockNs(): int
    {
        // microtime(true) gives seconds.fraction; scale to ns. Microsecond resolution
        // is plenty for span anchoring — sub-µs precision comes from the monotonic delta.
        return (int) round(microtime(true) * 1_000_000_000);
    }
}
