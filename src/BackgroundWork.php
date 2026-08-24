<?php

declare(strict_types=1);

namespace Restlytics\Laravel;

use Restlytics\Laravel\Support\Ids;

/** Framework-friendly background job, console command, and schedule tracing. */
final class BackgroundWork
{
    public function __construct(private readonly Tracer $tracer) {}

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    public function job(
        string $name,
        string $system,
        string $destination,
        callable $operation,
        int $attempt = 1,
        ?int $maxAttempts = null,
        ?int $enqueuedNs = null,
        ?string $messageId = null,
        ?string $traceparent = null,
    ): mixed {
        $this->startJob($name, $system, $destination, $attempt, $maxAttempts, $enqueuedNs, $messageId, $traceparent);

        try {
            return $operation();
        } catch (\Throwable $error) {
            $this->tracer->finishRootSpan(true);
            throw $error;
        } finally {
            if ($this->tracer->isSampled()) {
                $this->tracer->finishRootSpan();
            }
        }
    }

    public function startJob(
        string $name,
        string $system,
        string $destination,
        int $attempt = 1,
        ?int $maxAttempts = null,
        ?int $enqueuedNs = null,
        ?string $messageId = null,
        ?string $traceparent = null,
    ): void {
        $name = self::stable($name, 'unnamed-job');
        $this->tracer->startRootSpan($name, Span::KIND_CONSUMER, 'job', $traceparent, true);
        $root = $this->tracer->rootSpan();
        $root?->setString('restlytics.work.name', $name)
            ->setString('restlytics.job.name', $name)
            ->setString('messaging.system', self::stable($system, 'unknown'))
            ->setString('messaging.destination.name', self::stable($destination, 'unknown'))
            ->setString('messaging.operation.type', 'process')
            ->setInt('restlytics.job.attempt', max(1, $attempt));
        if ($maxAttempts !== null) {
            $root?->setInt('restlytics.job.max_attempts', max(1, $maxAttempts));
        }
        if ($enqueuedNs !== null) {
            $root?->setInt('restlytics.job.enqueued_ns', $enqueuedNs);
        }
        if ($messageId !== null && $messageId !== '') {
            $root?->setString('messaging.message.id', self::stable($messageId, 'unknown'));
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    public function command(string $name, callable $operation, ?string $traceparent = null): mixed
    {
        $this->startCommand($name, $traceparent);
        $root = $this->tracer->rootSpan();

        try {
            $result = $operation();
            $exitCode = is_int($result) ? $result : 0;
            $this->finishCommand($exitCode);

            return $result;
        } catch (\Throwable $error) {
            $root?->setInt('restlytics.command.exit_code', 1);
            $this->tracer->finishRootSpan(true);
            throw $error;
        }
    }

    public function startCommand(string $name, ?string $traceparent = null): void
    {
        $name = self::stable($name, 'unnamed-command');
        $this->tracer->startRootSpan($name, Span::KIND_SERVER, 'command', $traceparent);
        $this->tracer->rootSpan()?->setString('restlytics.work.name', $name)
            ->setString('restlytics.command.name', $name);
    }

    public function finishCommand(int $exitCode): void
    {
        $root = $this->tracer->rootSpan();
        $root?->setInt('restlytics.command.exit_code', $exitCode);
        if ($exitCode !== 0) {
            $root?->setStatus(Span::STATUS_ERROR);
        }
        $this->tracer->finishRootSpan($exitCode !== 0);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    public function schedule(string $name, string $cron, callable $operation, ?string $traceparent = null): mixed
    {
        $this->startSchedule($name, $cron, $traceparent);

        try {
            $result = $operation();
            $this->tracer->finishRootSpan();

            return $result;
        } catch (\Throwable $error) {
            $this->tracer->finishRootSpan(true);
            throw $error;
        }
    }

    public function startSchedule(string $name, string $cron, ?string $traceparent = null): void
    {
        $name = self::stable($name, 'unnamed-schedule');
        $this->tracer->startRootSpan($name, Span::KIND_SERVER, 'schedule', $traceparent);
        $this->tracer->rootSpan()?->setString('restlytics.work.name', $name)
            ->setString('restlytics.schedule.name', $name)
            ->setString('restlytics.schedule.cron', self::stable($cron, 'unknown'));
    }

    /**
     * @param  array<string, mixed>  $carrier
     * @return array<string, mixed>
     */
    public function injectQueueCarrier(array $carrier, string $system, string $destination, ?string $tracestate = null): array
    {
        if (! $this->tracer->isActive()) {
            return $carrier;
        }

        $spanId = Ids::spanId();
        $envelope = [
            'traceparent' => Ids::traceparent(
                $this->tracer->traceId(),
                $spanId,
                $this->tracer->isSampled(),
            ),
            'enqueued_ns' => $this->tracer->nowNs(),
        ];
        if ($tracestate !== null && trim($tracestate) !== '') {
            $envelope['tracestate'] = mb_substr(trim($tracestate), 0, 512);
        }
        $carrier['__restlytics'] = $envelope;

        $span = $this->tracer->startChildSpan(
            'send '.self::stable($destination, 'unknown'),
            'queue',
            Span::KIND_CLIENT,
            $spanId,
        );
        $span?->setString('messaging.system', self::stable($system, 'unknown'))
            ->setString('messaging.destination.name', self::stable($destination, 'unknown'))
            ->setString('messaging.operation.type', 'send')
            ->setStatus(Span::STATUS_OK)
            ->setEnd($this->tracer->nowNs());

        return $carrier;
    }

    private static function stable(string $value, string $fallback): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', trim($value)) ?? '';

        return mb_substr($value, 0, 200) ?: $fallback;
    }
}
