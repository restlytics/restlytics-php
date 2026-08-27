<?php

declare(strict_types=1);

namespace Restlytics\Laravel;

/**
 * Provider-neutral delivery boundary for production-shaped OpenTelemetry data.
 *
 * Implementations receive only the redacted OTLP request body. Restlytics ingest
 * credentials and tenant-routing metadata are deliberately not part of this
 * contract; an exporter owns any authentication required by its destination.
 */
interface Exporter
{
    /**
     * @param  array<string, mixed>  $payload  OTLP ExportTraceServiceRequest
     */
    public function exportTraces(array $payload): void;

    /**
     * @param  array<string, mixed>  $payload  OTLP ExportLogsServiceRequest
     */
    public function exportLogs(array $payload): void;
}
