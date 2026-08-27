<?php

declare(strict_types=1);

namespace Restlytics\Laravel\Transport;

/**
 * Legacy trace-delivery contract retained for backwards compatibility.
 *
 * New integrations should implement \Restlytics\Laravel\Exporter, which supports
 * both traces and logs. Existing transports that implement only send() continue
 * to receive traces; native logs are skipped safely.
 */
interface Transport
{
    /**
     * @param  array<string, mixed>  $payload  OTLP ExportTraceServiceRequest (associative array)
     */
    public function send(array $payload): void;
}
