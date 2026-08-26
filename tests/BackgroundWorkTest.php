<?php

declare(strict_types=1);

namespace Restlytics\Laravel\Tests;

use PHPUnit\Framework\TestCase;
use Restlytics\Laravel\Span;
use Restlytics\Laravel\Support\Ids;
use Restlytics\Laravel\Tracer;
use Restlytics\Laravel\Transport\NullTransport;
use Restlytics\Laravel\Work;

final class BackgroundWorkTest extends TestCase
{
    public function test_carrier_round_trip(): void
    {
        $payload = ['displayName' => 'App\\Jobs\\SendInvoice'];
        Ids::injectCarrier($payload, '4bf92f3577b34da6a3ce929d0e0e4736', '00f067aa0ba902b7', true);

        $this->assertArrayHasKey('__restlytics', $payload);
        $carrier = Ids::extractCarrier($payload);
        $this->assertNotNull($carrier);
        $this->assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $carrier['traceId']);
        $this->assertSame('00f067aa0ba902b7', $carrier['parentSpanId']);
        $this->assertTrue($carrier['sampled']);
    }

    public function test_extract_carrier_tolerates_garbage(): void
    {
        $this->assertNull(Ids::extractCarrier([]));
        $this->assertNull(Ids::extractCarrier(['__restlytics' => 'nope']));
        $this->assertNull(Ids::extractCarrier(['__restlytics' => ['traceparent' => 'bad']]));
    }

    public function test_job_root_wire_shape_with_orphan_parent_and_link(): void
    {
        $transport = new NullTransport;
        $tracer = new Tracer($transport, 'storefront', 'testing', 1.0);
        $work = new Work($tracer);
        $carrier = [
            'traceId' => '4bf92f3577b34da6a3ce929d0e0e4736',
            'parentSpanId' => '00f067aa0ba902b7',
            'sampled' => true,
        ];

        $work->startJob('App\\Jobs\\SendInvoiceEmail', $carrier, [
            'attempt' => 2,
            'destination' => 'emails',
        ]);
        $this->assertTrue($tracer->isSampled());
        $this->assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $tracer->traceId());

        $end = $tracer->nowNs();
        $start = $end - 1_000_000;
        $child = $tracer->addChildSpan('db select', $start, $end);
        $child?->setString('restlytics.category', 'db');

        $work->finishJob(false);

        $this->assertNotNull($transport->lastPayload);
        $spans = $transport->lastPayload['resourceSpans'][0]['scopeSpans'][0]['spans'] ?? [];
        $this->assertNotEmpty($spans);
        $root = $spans[0];
        $this->assertSame(Span::KIND_CONSUMER, $root['kind']);
        $this->assertSame('00f067aa0ba902b7', $root['parentSpanId']);
        $attrs = $this->attrMap($root);
        $this->assertSame('job', $attrs['restlytics.category'] ?? null);
        $this->assertSame('App\\Jobs\\SendInvoiceEmail', $attrs['restlytics.work.name'] ?? null);
        $this->assertSame('2', $attrs['restlytics.job.attempt'] ?? null);
        $this->assertArrayHasKey('restlytics.self_ns.queue', $attrs);
        $this->assertArrayHasKey('links', $root);
        $this->assertSame('enqueue', $root['links'][0]['attributes'][0]['value']['stringValue'] ?? null);
    }

    public function test_continued_job_honors_sampled_bit_at_rate_zero(): void
    {
        $tracer = new Tracer(new NullTransport(), 'api', 'test', 0.0);
        $work = new Work($tracer);
        $work->startJob('App\\Jobs\\X', [
            'traceId' => '4bf92f3577b34da6a3ce929d0e0e4736',
            'parentSpanId' => '00f067aa0ba902b7',
            'sampled' => true,
        ]);
        $this->assertTrue($tracer->isSampled());
    }

    public function test_sync_dispatch_does_not_open_second_root(): void
    {
        $tracer = new Tracer(new NullTransport(), 'api', 'test', 1.0);
        $work = new Work($tracer);
        $tracer->startServerSpan('GET /checkout');
        $httpId = $tracer->rootSpanId();
        $work->startJob('App\\Jobs\\Inline');
        $this->assertSame($httpId, $tracer->rootSpanId());
        $this->assertSame(Span::KIND_SERVER, $tracer->rootSpan()?->kind);
    }

    public function test_command_failure_sets_error_status(): void
    {
        $transport = new NullTransport;
        $tracer = new Tracer($transport, 'api', 'test', 1.0);
        $work = new Work($tracer);
        $work->startCommand('orders:purge-stale');
        $work->finishCommand(1);
        $root = $transport->lastPayload['resourceSpans'][0]['scopeSpans'][0]['spans'][0] ?? null;
        $this->assertNotNull($root);
        $attrs = $this->attrMap($root);
        $this->assertSame('command', $attrs['restlytics.category'] ?? null);
        $this->assertSame('1', $attrs['restlytics.command.exit_code'] ?? null);
        $this->assertSame(Span::STATUS_ERROR, $root['status']['code'] ?? null);
    }

    public function test_schedule_root_keeps_cron_out_of_name(): void
    {
        $transport = new NullTransport;
        $tracer = new Tracer($transport, 'api', 'test', 1.0);
        $work = new Work($tracer);
        $work->startSchedule('heartbeat', '*/5 * * * *');
        $work->finishSchedule(false);
        $root = $transport->lastPayload['resourceSpans'][0]['scopeSpans'][0]['spans'][0] ?? null;
        $this->assertNotNull($root);
        $this->assertSame('heartbeat', $root['name']);
        $attrs = $this->attrMap($root);
        $this->assertSame('schedule', $attrs['restlytics.category'] ?? null);
        $this->assertSame('*/5 * * * *', $attrs['restlytics.schedule.cron'] ?? null);
    }

    public function test_http_finish_emits_queue_self_time_bucket(): void
    {
        $transport = new NullTransport;
        $tracer = new Tracer($transport, 'api', 'test', 1.0);
        $work = new Work($tracer);
        $tracer->startServerSpan('POST /checkout');
        $payload = [];
        $work->recordEnqueue($payload, 'default');
        $this->assertArrayHasKey('__restlytics', $payload);
        $tracer->finishServerSpan();
        $root = $transport->lastPayload['resourceSpans'][0]['scopeSpans'][0]['spans'][0] ?? null;
        $attrs = $this->attrMap($root ?? []);
        $this->assertArrayHasKey('restlytics.self_ns.queue', $attrs);
        $this->assertSame('0', $attrs['restlytics.self_ns.queue']);
        $this->assertSame('app', $attrs['restlytics.category'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $span
     * @return array<string, string>
     */
    private function attrMap(array $span): array
    {
        $out = [];
        foreach ($span['attributes'] ?? [] as $kv) {
            $key = $kv['key'] ?? null;
            if (! is_string($key)) {
                continue;
            }
            $value = $kv['value']['stringValue'] ?? $kv['value']['intValue'] ?? null;
            if (is_string($value) || is_int($value)) {
                $out[$key] = (string) $value;
            }
        }

        return $out;
    }
}
