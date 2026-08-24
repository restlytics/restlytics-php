<?php

declare(strict_types=1);

namespace Restlytics\Laravel\Tests;

use PHPUnit\Framework\TestCase;
use Restlytics\Laravel\BackgroundWork;
use Restlytics\Laravel\Tracer;
use Restlytics\Laravel\Transport\PreviewTransport;

final class BackgroundWorkTest extends TestCase
{
    public function test_job_continues_enqueue_trace_and_redacts_failures(): void
    {
        [$work, $transport] = $this->work();
        $parent = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';

        try {
            $work->job(
                'App\\Jobs\\SendInvoice',
                'redis',
                'emails',
                static fn () => throw new \RuntimeException('customer-secret'),
                attempt: 2,
                traceparent: $parent,
            );
            self::fail('expected operation failure');
        } catch (\RuntimeException) {
        }

        $root = $transport->reports[0]['payload']['resourceSpans'][0]['scopeSpans'][0]['spans'][0];
        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $root['traceId']);
        self::assertSame(5, $root['kind']);
        self::assertSame(2, $root['status']['code']);
        self::assertCount(1, $root['links']);
        self::assertStringNotContainsString('customer-secret', json_encode($root, JSON_THROW_ON_ERROR));
    }

    public function test_queue_carrier_and_nonzero_command_are_recorded(): void
    {
        [$work, $transport, $tracer] = $this->work();
        $tracer->startServerSpan('POST /reports');
        $carrier = $work->injectQueueCarrier(['payload' => 'never-exported'], 'redis', 'reports');
        $tracer->finishServerSpan();

        self::assertArrayHasKey('traceparent', $carrier['__restlytics']);
        $requestSpans = $transport->reports[0]['payload']['resourceSpans'][0]['scopeSpans'][0]['spans'];
        self::assertSame('queue', $this->attributes($requestSpans[1])['restlytics.category']['stringValue']);
        self::assertStringNotContainsString('never-exported', json_encode($transport->reports[0], JSON_THROW_ON_ERROR));

        self::assertSame(3, $work->command('reports:generate', static fn (): int => 3));
        $command = $transport->reports[1]['payload']['resourceSpans'][0]['scopeSpans'][0]['spans'][0];
        self::assertSame(2, $command['status']['code']);
        self::assertSame('3', $this->attributes($command)['restlytics.command.exit_code']['intValue']);
    }

    public function test_unsampled_queue_context_propagates_without_exporting(): void
    {
        $transport = new PreviewTransport(0.0);
        $tracer = new Tracer($transport, 'worker', 'test', 0.0);
        $work = new BackgroundWork($tracer);
        $tracer->startServerSpan('POST /reports');

        $carrier = $work->injectQueueCarrier([], 'redis', 'reports');

        self::assertStringEndsWith('-00', $carrier['__restlytics']['traceparent']);
        $tracer->finishServerSpan();
        self::assertSame([], $transport->reports);
    }

    /** @return array{BackgroundWork, PreviewTransport, Tracer} */
    private function work(): array
    {
        $transport = new PreviewTransport(1.0);
        $tracer = new Tracer($transport, 'worker', 'test');

        return [new BackgroundWork($tracer), $transport, $tracer];
    }

    /** @param array<string, mixed> $span
     * @return array<string, mixed>
     */
    private function attributes(array $span): array
    {
        $attributes = [];
        foreach ($span['attributes'] ?? [] as $attribute) {
            $attributes[$attribute['key']] = $attribute['value'];
        }

        return $attributes;
    }
}
