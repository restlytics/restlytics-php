<?php

declare(strict_types=1);

namespace Restlytics\Laravel\Tests;

use PHPUnit\Framework\TestCase;
use Restlytics\Laravel\Tracer;
use Restlytics\Laravel\Transport\NullTransport;

/**
 * Head-based sampling is decided ONCE per trace (SPEC §3):
 *  - continued trace  → inherit the incoming traceparent's sampled bit verbatim
 *  - root trace       → roll locally against sample_rate
 *
 * These run at sample_rate 0.0, so a local re-roll always says "drop" — which is
 * exactly what would break a continued-and-sampled trace.
 */
final class TracerSamplingTest extends TestCase
{
    private const TRACEPARENT_SAMPLED = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';

    private const TRACEPARENT_NOT_SAMPLED = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-00';

    public function test_continued_trace_inherits_upstream_sampled_bit(): void
    {
        // Upstream said KEEP. We must not re-roll and drop it mid-chain, even at rate 0.
        $tracer = $this->tracer(0.0);
        $tracer->startServerSpan('GET /users/{id}', self::TRACEPARENT_SAMPLED);

        $this->assertTrue($tracer->isSampled());
        $this->assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $tracer->traceId());
    }

    public function test_continued_trace_respects_upstream_not_sampled_bit(): void
    {
        $tracer = $this->tracer(1.0);
        $tracer->startServerSpan('GET /users/{id}', self::TRACEPARENT_NOT_SAMPLED);

        $this->assertFalse($tracer->isSampled());
    }

    public function test_root_trace_still_rolls_locally(): void
    {
        // No traceparent → nothing to inherit, so rate 0.0 drops it.
        $tracer = $this->tracer(0.0);
        $tracer->startServerSpan('GET /users/{id}');

        $this->assertFalse($tracer->isSampled());
    }

    private function tracer(float $sampleRate): Tracer
    {
        return new Tracer(new NullTransport(), 'checkout-api', 'test', $sampleRate);
    }
}
