<?php

declare(strict_types=1);

namespace Restlytics\Laravel;

use Restlytics\Laravel\Otlp\LogPayload;
use Restlytics\Laravel\Support\Redaction;
use Restlytics\Laravel\Transport\Transport;

/** Bounded, opt-in application-log capture with source redaction and trace correlation. */
final class LogBuffer
{
    /** @var list<array<string, mixed>> */
    private array $records = [];

    private bool $flushing = false;

    public function __construct(
        private readonly Tracer $tracer,
        private readonly Transport $transport,
        private readonly string $serviceName,
        private readonly string $environment,
        private readonly bool $enabled = false,
        private readonly int $minSeverity = 13,
        private readonly int $maxRecords = 256,
    ) {}

    /** Deterministic OpenTelemetry severity mapping shared by every SDK. */
    public static function severityNumber(string|int $level): int
    {
        if (is_int($level)) {
            return match (true) {
                $level >= 600 => 21,
                $level >= 550 => 21,
                $level >= 500 => 18,
                $level >= 400 => 17,
                $level >= 300 => 13,
                $level >= 250 => 10,
                $level >= 200 => 9,
                default => 5,
            };
        }

        return match (strtolower(trim($level))) {
            'fatal', 'alert', 'emergency' => 21,
            'critical' => 18,
            'error' => 17,
            'warning', 'warn' => 13,
            'notice' => 10,
            'info', 'information' => 9,
            default => 5,
        };
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function capture(string|int $level, string $message, array $context = []): void
    {
        try {
            $severity = self::severityNumber($level);
            if (! $this->enabled || $this->flushing || $severity < $this->boundedMinSeverity()) {
                return;
            }
            if (count($this->records) >= max(1, $this->maxRecords)) {
                return;
            }

            $nowNs = $this->tracer->isActive()
                ? $this->tracer->nowNs()
                : (int) round(microtime(true) * 1_000_000_000);
            $traceId = $this->tracer->currentTraceId();
            $spanId = $this->tracer->currentSpanId();
            $record = [
                'timeUnixNano' => (string) $nowNs,
                'observedTimeUnixNano' => (string) $nowNs,
                'severityNumber' => $severity,
                'severityText' => self::severityText($severity),
                'body' => ['stringValue' => Redaction::logText($message)],
                'attributes' => self::safeAttributes($context),
            ];
            if ($traceId !== null) {
                $record['traceId'] = $traceId;
                $record['flags'] = $this->tracer->traceFlags();
            }
            if ($spanId !== null) {
                $record['spanId'] = $spanId;
            }
            $this->records[] = $record;
        } catch (\Throwable) {
            // A log hook is never allowed to affect the host logger.
        }
    }

    public function flush(): void
    {
        if (! $this->enabled || $this->flushing || $this->records === []) {
            return;
        }

        $records = $this->records;
        $this->records = [];
        $this->flushing = true;
        try {
            $this->transport->sendLogs(LogPayload::build(
                $this->serviceName,
                $this->environment,
                $records,
            ));
        } catch (\Throwable) {
            // Transport failures are intentionally swallowed and never retried inline.
        } finally {
            $this->flushing = false;
        }
    }

    public function count(): int
    {
        return count($this->records);
    }

    private function boundedMinSeverity(): int
    {
        return max(0, min(24, $this->minSeverity));
    }

    private static function severityText(int $severity): string
    {
        return match ($severity) {
            21 => 'FATAL',
            18 => 'ERROR2',
            17 => 'ERROR',
            13 => 'WARN',
            10 => 'INFO2',
            9 => 'INFO',
            default => 'DEBUG',
        };
    }

    /**
     * Keep only scalar, non-sensitive structured fields. Values are scrubbed
     * before buffering and never include exception objects or stack traces.
     *
     * @param  array<string, mixed>  $context
     * @return list<array{key:string,value:array{stringValue:string}}>
     */
    private static function safeAttributes(array $context): array
    {
        $attributes = [];
        foreach ($context as $key => $value) {
            $key = mb_substr((string) $key, 0, 128);
            if ($key === '' || Redaction::isSensitiveAttributeKey($key) || ! is_scalar($value)) {
                continue;
            }
            $attributes[] = [
                'key' => $key,
                'value' => ['stringValue' => Redaction::logText((string) $value, 1024)],
            ];
            if (count($attributes) >= 32) {
                break;
            }
        }

        return $attributes;
    }
}
