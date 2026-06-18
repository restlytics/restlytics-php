<?php

declare(strict_types=1);

namespace Restlytics\Laravel\Transport;

/**
 * Ships a fully-built OTLP/JSON ExportTraceServiceRequest to the ingestion service.
 *
 * Implementations MUST be fire-and-forget and MUST NOT throw — telemetry must
 * never be able to fail (or slow) the host application's request. Any transport
 * error is swallowed (and optionally logged), never surfaced.
 */
interface Transport
{
    /**
     * @param array<string, mixed> $payload OTLP ExportTraceServiceRequest (associative array)
     */
    public function send(array $payload): void;
}
