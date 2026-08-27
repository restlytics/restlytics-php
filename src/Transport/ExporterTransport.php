<?php

declare(strict_types=1);

namespace Restlytics\Laravel\Transport;

use Restlytics\Laravel\Exporter;

/**
 * Adapts the public exporter contract to the SDK's legacy transport boundary.
 *
 * Customer code is invoked synchronously at the existing lifecycle flush point.
 * Calls are never retried or queued here, re-entrant exports are dropped, and no
 * exception is allowed to cross back into the host application.
 */
final class ExporterTransport implements LogsTransport, Transport
{
    private bool $exporting = false;

    public function __construct(private readonly Exporter $exporter) {}

    public function send(array $payload): void
    {
        $this->export(static function (Exporter $exporter) use ($payload): void {
            $exporter->exportTraces($payload);
        });
    }

    public function sendLogs(array $payload): void
    {
        $this->export(static function (Exporter $exporter) use ($payload): void {
            $exporter->exportLogs($payload);
        });
    }

    /** @param callable(Exporter): void $operation */
    private function export(callable $operation): void
    {
        if ($this->exporting) {
            return;
        }

        $this->exporting = true;
        try {
            $operation($this->exporter);
        } catch (\Throwable) {
            // Customer delivery code can never affect requests, jobs, or commands.
        } finally {
            $this->exporting = false;
        }
    }
}
