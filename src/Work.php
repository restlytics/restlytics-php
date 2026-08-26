<?php

declare(strict_types=1);

namespace Restlytics\Laravel;

use Restlytics\Laravel\Support\Ids;

/**
 * Background-work lifecycle helpers (SPEC §11) — jobs, Artisan commands, schedules.
 * Thin wrappers over {@see Tracer::startRootSpan} / {@see Tracer::finishRootSpan}.
 */
final class Work
{
    public function __construct(private readonly Tracer $tracer) {}

    /**
     * Start a CONSUMER job root. Pass a parsed carrier from Ids::extractCarrier when
     * the job was enqueued under an active HTTP/job trace.
     *
     * @param  array{traceId: string, parentSpanId: string, sampled: bool}|null  $carrier
     * @param  array{system?: string, destination?: string, messageId?: string, attempt?: int, maxAttempts?: int}  $messaging
     */
    public function startJob(string $name, ?array $carrier = null, array $messaging = []): void
    {
        // sync / afterResponse: already inside an HTTP (or other) root — never open a second root.
        if ($this->tracer->hasActiveRoot()) {
            return;
        }

        $this->tracer->startRootSpan($name, Span::KIND_CONSUMER, carrier: $carrier);
        $root = $this->tracer->rootSpan();
        if ($root === null) {
            return;
        }

        if ($carrier !== null) {
            $root->addLink($carrier['traceId'], $carrier['parentSpanId'], [
                'restlytics.link.kind' => 'enqueue',
            ]);
        }

        $system = $messaging['system'] ?? 'laravel';
        $destination = $messaging['destination'] ?? 'default';
        $root
            ->setString('restlytics.work.name', $name)
            ->setString('restlytics.job.name', $name)
            ->setString('messaging.system', $system)
            ->setString('messaging.destination.name', $destination)
            ->setString('messaging.operation.type', 'process');

        if (isset($messaging['messageId']) && $messaging['messageId'] !== '') {
            $root->setString('messaging.message.id', $messaging['messageId']);
        }
        if (isset($messaging['attempt'])) {
            $root->setInt('restlytics.job.attempt', max(1, (int) $messaging['attempt']));
        }
        if (isset($messaging['maxAttempts'])) {
            $root->setInt('restlytics.job.max_attempts', max(1, (int) $messaging['maxAttempts']));
        }
    }

    public function finishJob(bool $failed = false): void
    {
        $root = $this->tracer->rootSpan();
        if ($root === null || $root->kind !== Span::KIND_CONSUMER) {
            return;
        }

        $this->tracer->finishRootSpan('job', static function (Span $span) use ($failed): void {
            $span->setString('restlytics.work.name', $span->name);
            if ($failed) {
                $span->setStatus(Span::STATUS_ERROR);
            }
        });
    }

    public function startCommand(string $name): void
    {
        if ($this->tracer->hasActiveRoot()) {
            return;
        }
        $this->tracer->startRootSpan($name, Span::KIND_SERVER);
        $this->tracer->rootSpan()
            ?->setString('restlytics.work.name', $name)
            ->setString('restlytics.command.name', $name);
    }

    public function finishCommand(int $exitCode = 0): void
    {
        $root = $this->tracer->rootSpan();
        if ($root === null || $root->kind !== Span::KIND_SERVER) {
            return;
        }
        // Don't finish an HTTP root via the command path.
        if ($root->stringAttribute('http.request.method') !== null) {
            return;
        }

        $this->tracer->finishRootSpan('command', static function (Span $span) use ($exitCode, $root): void {
            $name = $root->name;
            $span
                ->setString('restlytics.work.name', $name)
                ->setString('restlytics.command.name', $name)
                ->setInt('restlytics.command.exit_code', $exitCode);
            if ($exitCode !== 0) {
                $span->setStatus(Span::STATUS_ERROR);
            }
        });
    }

    public function startSchedule(string $name, ?string $cron = null): void
    {
        if ($this->tracer->hasActiveRoot()) {
            return;
        }
        $this->tracer->startRootSpan($name, Span::KIND_SERVER);
        $root = $this->tracer->rootSpan();
        $root?->setString('restlytics.work.name', $name)->setString('restlytics.schedule.name', $name);
        if ($cron !== null && $cron !== '') {
            $root?->setString('restlytics.schedule.cron', $cron);
        }
    }

    public function finishSchedule(bool $failed = false): void
    {
        $root = $this->tracer->rootSpan();
        if ($root === null || $root->kind !== Span::KIND_SERVER) {
            return;
        }
        if ($root->stringAttribute('http.request.method') !== null) {
            return;
        }
        if ($root->stringAttribute('restlytics.command.name') !== null) {
            return;
        }

        $this->tracer->finishRootSpan('schedule', static function (Span $span) use ($failed, $root): void {
            $span->setString('restlytics.work.name', $root->name);
            $span->setString('restlytics.schedule.name', $root->name);
            if ($failed) {
                $span->setStatus(Span::STATUS_ERROR);
            }
        });
    }

    /**
     * Emit a CLIENT enqueue child under the active root and inject the queue carrier.
     *
     * @param  array<string, mixed>  $payload
     */
    public function recordEnqueue(array &$payload, string $destination = 'default', string $system = 'laravel'): ?Span
    {
        if (! $this->tracer->isSampled() || ! $this->tracer->hasActiveRoot()) {
            return null;
        }

        $now = $this->tracer->nowNs();
        // Zero-duration marker when we only learn about enqueue at createPayload time;
        // JobQueued can extend the end if timing is available.
        $span = $this->tracer->addChildSpan('queue send '.$destination, $now, $now);
        if ($span === null) {
            return null;
        }

        $span
            ->setString('restlytics.category', 'queue')
            ->setString('messaging.system', $system)
            ->setString('messaging.destination.name', $destination)
            ->setString('messaging.operation.type', 'send');

        Ids::injectCarrier($payload, $this->tracer->traceId(), $span->spanId, $this->tracer->isSampled());

        return $span;
    }
}
