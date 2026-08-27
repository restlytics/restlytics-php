<?php

declare(strict_types=1);

namespace Restlytics\Laravel\Transport;

/** @internal Optional log capability used alongside the legacy Transport API. */
interface LogsTransport
{
    /** @param array<string, mixed> $payload OTLP ExportLogsServiceRequest */
    public function sendLogs(array $payload): void;
}
