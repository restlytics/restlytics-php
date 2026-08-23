<?php

declare(strict_types=1);

namespace Restlytics\Laravel\Tests;

use PHPUnit\Framework\TestCase;
use Restlytics\Laravel\Transport\CurlTransport;

final class TransportReliabilityTest extends TestCase
{
    public function test_unconfigured_and_closed_delivery_is_dropped_and_observable(): void
    {
        $errors = [];
        $transport = new CurlTransport('', '', 25, static function (string $message) use (&$errors): void {
            $errors[] = $message;
        });

        $transport->send([]);
        self::assertSame(1, $transport->diagnostics()->droppedBatches);
        self::assertSame(0, $transport->diagnostics()->acceptedBatches);
        self::assertStringContainsString('closed or unconfigured', $errors[0]);
        self::assertTrue($transport->close());
        $transport->send([]);
        self::assertSame(2, $transport->diagnostics()->droppedBatches);
    }

    public function test_network_failure_is_hard_bounded_swallowed_and_never_retried(): void
    {
        $transport = new CurlTransport('http://127.0.0.1:1', 'rl_test', 25);
        $started = hrtime(true);

        $transport->send([]);

        $elapsedMs = (hrtime(true) - $started) / 1_000_000;
        self::assertLessThan(500, $elapsedMs);
        self::assertSame(1, $transport->diagnostics()->acceptedBatches);
        self::assertSame(1, $transport->diagnostics()->failedBatches);
        self::assertSame(0, $transport->diagnostics()->deliveredBatches);
        self::assertSame(0, $transport->diagnostics()->queuedBatches);
        self::assertSame(1, $transport->diagnostics()->queueCapacity);
        self::assertTrue($transport->flush());
    }
}
