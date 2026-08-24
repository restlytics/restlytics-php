<?php

declare(strict_types=1);

namespace Restlytics\Laravel\Tests;

use PHPUnit\Framework\TestCase;
use Restlytics\Laravel\LogBuffer;
use Restlytics\Laravel\Tracer;
use Restlytics\Laravel\Transport\NullTransport;
use Restlytics\Laravel\Transport\Transport;

final class LogBufferTest extends TestCase
{
    public function test_severity_mapping_is_deterministic(): void
    {
        self::assertSame(5, LogBuffer::severityNumber('debug'));
        self::assertSame(9, LogBuffer::severityNumber('info'));
        self::assertSame(10, LogBuffer::severityNumber('notice'));
        self::assertSame(13, LogBuffer::severityNumber('warning'));
        self::assertSame(17, LogBuffer::severityNumber('error'));
        self::assertSame(18, LogBuffer::severityNumber('critical'));
        self::assertSame(21, LogBuffer::severityNumber('emergency'));
        self::assertSame(13, LogBuffer::severityNumber(300));
    }

    public function test_capture_is_opt_in_thresholded_correlated_and_source_redacted(): void
    {
        $transport = new NullTransport;
        $tracer = new Tracer($transport, 'checkout', 'test');
        $disabled = new LogBuffer($tracer, $transport, 'checkout', 'test');
        $disabled->capture('error', 'never buffered');
        self::assertSame(0, $disabled->count());

        $logs = new LogBuffer($tracer, $transport, 'checkout', 'test', true, 13, 4);
        $logs->capture('info', 'below threshold');
        $tracer->startServerSpan(
            'GET /checkout',
            '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
        );
        $logs->capture('error', 'token=customer-secret email buyer@example.test https://u:p@example.test/a?key=value request_body=raw bindings=[raw] exception=raw -----BEGIN PRIVATE KEY----- raw -----END PRIVATE KEY-----', [
            'safe.order' => 42,
            'authorization' => 'Bearer customer-secret',
            'exception' => new \RuntimeException('customer-secret'),
        ]);
        $logs->flush();

        self::assertCount(1, $transport->sentLogs);
        $payload = $transport->sentLogs[0];
        $record = $payload['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0];
        self::assertSame(17, $record['severityNumber']);
        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $record['traceId']);
        self::assertSame($tracer->rootSpanId(), $record['spanId']);
        self::assertSame(1, $record['flags']);
        self::assertSame([['key' => 'safe.order', 'value' => ['stringValue' => '42']]], $record['attributes']);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('[REDACTED]', $encoded);
        self::assertStringNotContainsString('customer-secret', $encoded);
        self::assertStringNotContainsString('buyer@example.test', $encoded);
        self::assertStringNotContainsString('u:p', $encoded);
        self::assertStringNotContainsString('?key=value', $encoded);
        self::assertStringNotContainsString('request_body=raw', $encoded);
        self::assertStringNotContainsString('bindings=[raw]', $encoded);
        self::assertStringNotContainsString('exception=raw', $encoded);
        self::assertStringNotContainsString('PRIVATE KEY', $encoded);
    }

    public function test_outside_trace_omits_ids_and_buffer_is_bounded(): void
    {
        $transport = new NullTransport;
        $tracer = new Tracer($transport, 'worker', 'test');
        $logs = new LogBuffer($tracer, $transport, 'worker', 'test', true, 5, 2);
        $logs->capture('debug', 'one');
        $logs->capture('warning', 'two');
        $logs->capture('error', 'dropped');
        self::assertSame(2, $logs->count());
        $logs->flush();

        $records = $transport->sentLogs[0]['resourceLogs'][0]['scopeLogs'][0]['logRecords'];
        self::assertCount(2, $records);
        self::assertArrayNotHasKey('traceId', $records[0]);
        self::assertArrayNotHasKey('spanId', $records[0]);
    }

    public function test_unsampled_trace_still_correlates_logs_with_cleared_flags(): void
    {
        $transport = new NullTransport;
        $tracer = new Tracer($transport, 'worker', 'test', 0.0);
        $logs = new LogBuffer($tracer, $transport, 'worker', 'test', true);

        $tracer->startServerSpan('GET /unsampled');
        $logs->capture('error', 'retained independently');
        $logs->flush();

        $record = $transport->sentLogs[0]['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0];
        self::assertSame($tracer->traceId(), $record['traceId']);
        self::assertSame($tracer->rootSpanId(), $record['spanId']);
        self::assertSame(0, $record['flags']);
        self::assertNull($tracer->rootSpan());
    }

    public function test_transport_failure_never_escapes_into_the_host_logger(): void
    {
        $transport = new class implements Transport
        {
            public function send(array $payload): void {}

            public function sendLogs(array $payload): void
            {
                throw new \RuntimeException('offline');
            }
        };
        $tracer = new Tracer($transport, 'worker', 'test');
        $logs = new LogBuffer($tracer, $transport, 'worker', 'test', true);
        $logs->capture('error', 'still safe');
        $logs->flush();
        self::assertSame(0, $logs->count());
    }
}
