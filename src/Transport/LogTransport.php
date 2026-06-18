<?php

declare(strict_types=1);

namespace Restlytics\Laravel\Transport;

/**
 * Writes the OTLP payload to the Laravel log instead of the network. Handy for
 * local development and debugging the wire shape ("what would we send?") without
 * standing up an ingestion service.
 *
 * Select with RESTLYTICS_TRANSPORT=log.
 *
 * Resolves the logger at call-time (not in the constructor) so it stays
 * Octane-safe and doesn't capture a stale container binding.
 */
final class LogTransport implements Transport
{
    public function __construct(
        /** Callback that performs the actual log write: fn(string $json): void */
        private $writer,
    ) {
    }

    public function send(array $payload): void
    {
        try {
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            if ($json !== false && \is_callable($this->writer)) {
                ($this->writer)($json);
            }
        } catch (\Throwable) {
            // Never throw into the host app, even for a dev transport.
        }
    }
}
