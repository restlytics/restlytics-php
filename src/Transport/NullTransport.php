<?php

declare(strict_types=1);

namespace Restlytics\Laravel\Transport;

/**
 * No-op transport. Useful in tests, local dev, and CI where you don't want to
 * (or can't) reach the ingestion service. Optionally records the last payload so
 * tests can assert on the built OTLP body without any network.
 *
 * Select with RESTLYTICS_TRANSPORT=null.
 */
final class NullTransport implements Transport
{
    /** @var array<string, mixed>|null */
    public ?array $lastPayload = null;

    /** @var list<array<string, mixed>> */
    public array $sent = [];

    public function send(array $payload): void
    {
        $this->lastPayload = $payload;
        $this->sent[] = $payload;
    }
}
