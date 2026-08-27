<?php

declare(strict_types=1);

namespace Restlytics\Laravel\Tests;

use PHPUnit\Framework\TestCase;
use Restlytics\Laravel\LogBuffer;
use Restlytics\Laravel\Tracer;
use Restlytics\Laravel\Transport\Transport;

final class ExporterCompatibilityTest extends TestCase
{
    public function test_released_trace_only_transport_contract_remains_usable(): void
    {
        $transport = new class implements Transport
        {
            /** @var list<array<string, mixed>> */
            public array $payloads = [];

            public function send(array $payload): void
            {
                $this->payloads[] = $payload;
            }
        };
        $tracer = new Tracer($transport, 'legacy-app', 'test');
        $logs = new LogBuffer($tracer, $transport, 'legacy-app', 'test', true);

        $tracer->startServerSpan('GET /legacy');
        $logs->capture('error', 'logs are optional for a legacy transport');
        $tracer->finishServerSpan();
        $logs->flush();

        self::assertCount(1, $transport->payloads);
        self::assertArrayHasKey('resourceSpans', $transport->payloads[0]);
        self::assertSame(0, $logs->count());
    }

    public function test_throwing_legacy_transport_is_still_isolated(): void
    {
        $transport = new class implements Transport
        {
            public function send(array $payload): void
            {
                throw new \RuntimeException('offline');
            }
        };
        $tracer = new Tracer($transport, 'legacy-app', 'test');
        $tracer->startServerSpan('GET /safe');
        $tracer->finishServerSpan();

        self::assertFalse($tracer->isActive());
    }
}
