<?php

declare(strict_types=1);

namespace Restlytics\Laravel\Transport;

/**
 * Default transport: gzip the JSON body and POST it with cURL.
 *
 * Design constraints (all in service of "telemetry must never hurt the host app"):
 *  - Runs in `terminate()`, AFTER the response is flushed to the client (FPM),
 *    so its latency is invisible to the end user.
 *  - Hard short timeout (CURLOPT_TIMEOUT_MS) so a slow/unreachable ingest endpoint
 *    can't pile up worker time.
 *  - Every error path is swallowed. We never throw into the host application.
 *
 * Wire format (must match the ingestion contract exactly):
 *   POST {ingestUrl}/v1/traces
 *   X-Restlytics-Key: {key}
 *   Content-Type: application/json
 *   Content-Encoding: gzip
 *   body = gzip(json)
 */
final class CurlTransport implements Transport
{
    private int $acceptedBatches = 0;

    private int $deliveredBatches = 0;

    private int $droppedBatches = 0;

    private int $failedBatches = 0;

    private int $inFlightBatches = 0;

    private bool $closed = false;

    public function __construct(
        private readonly string $ingestUrl,
        private readonly string $key,
        private readonly int $timeoutMs = 2000,
        /** Optional PSR-3-ish logger callback: fn(string $message): void */
        private $onError = null,
    ) {}

    public function send(array $payload): void
    {
        $this->sendTo('/v1/traces', $payload);
    }

    public function sendLogs(array $payload): void
    {
        $this->sendTo('/v1/logs', $payload);
    }

    /** @param array<string, mixed> $payload */
    private function sendTo(string $path, array $payload): void
    {
        // Defensive: without the basics, there's nothing useful to do — and we
        // must not throw, so just bail quietly.
        if ($this->closed || $this->ingestUrl === '' || $this->key === '' || ! \function_exists('curl_init')) {
            $this->recordDrop('restlytics: batch dropped because transport is closed or unconfigured');

            return;
        }

        $this->acceptedBatches++;
        $this->inFlightBatches = 1;
        try {
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                $this->reportError('restlytics: failed to encode payload');
                $this->failedBatches++;

                return;
            }

            $body = gzencode($json, 6);
            if ($body === false) {
                // gzip is required by the contract's Content-Encoding header; if it
                // somehow fails, drop the batch rather than send a mislabeled body.
                $this->reportError('restlytics: gzip failed');
                $this->failedBatches++;

                return;
            }

            $url = rtrim($this->ingestUrl, '/').$path;

            $ch = curl_init($url);
            if ($ch === false) {
                $this->failedBatches++;

                return;
            }

            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Content-Encoding: gzip',
                    'X-Restlytics-Key: '.$this->key,
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT_MS => $this->timeoutMs,
                // Bound the connect phase too, otherwise a black-holed host eats the
                // whole timeout budget on DNS/TCP alone.
                CURLOPT_CONNECTTIMEOUT_MS => min($this->timeoutMs, 1000),
                CURLOPT_NOSIGNAL => true, // required for ms-level timeouts to be honored
            ]);

            $ok = curl_exec($ch);
            if ($ok === false) {
                $this->reportError('restlytics: send failed: '.curl_error($ch));
                $this->failedBatches++;
            } else {
                $this->deliveredBatches++;
            }

            curl_close($ch);
        } catch (\Throwable $e) {
            // Absolute backstop — nothing here may ever propagate.
            $this->reportError('restlytics: transport exception: '.$e->getMessage());
            $this->failedBatches++;
        } finally {
            $this->inFlightBatches = 0;
        }
    }

    /** PHP-FPM sends one terminated request batch synchronously, so there is no background queue. */
    public function diagnostics(): TransportDiagnostics
    {
        return new TransportDiagnostics(
            acceptedBatches: $this->acceptedBatches,
            deliveredBatches: $this->deliveredBatches,
            droppedBatches: $this->droppedBatches,
            failedBatches: $this->failedBatches,
            queuedBatches: 0,
            inFlightBatches: $this->inFlightBatches,
            queueCapacity: 1,
            closed: $this->closed,
        );
    }

    public function flush(int $timeoutMs = 2000): bool
    {
        // Delivery happens after fastcgi_finish_request() and completes inside send().
        return true;
    }

    public function close(int $timeoutMs = 2000): bool
    {
        $this->closed = true;

        return true;
    }

    private function recordDrop(string $message): void
    {
        $this->droppedBatches++;
        $this->reportError($message);
    }

    private function reportError(string $message): void
    {
        if (\is_callable($this->onError)) {
            try {
                ($this->onError)($message);
            } catch (\Throwable) {
                // Even logging must not throw.
            }
        }
    }
}
