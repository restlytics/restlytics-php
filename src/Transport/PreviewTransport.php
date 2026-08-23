<?php

declare(strict_types=1);

namespace Restlytics\Laravel\Transport;

/**
 * Emits a structured production-payload preview locally and never opens a socket.
 * Select with RESTLYTICS_TRANSPORT=preview before connecting production data.
 */
final class PreviewTransport implements Transport
{
    /** @var list<array<string, mixed>> */
    public array $reports = [];

    public function __construct(
        private readonly float $sampleRate,
        /** Callback that performs the local write: fn(string $json): void */
        private $writer = null,
    ) {}

    public function send(array $payload): void
    {
        try {
            $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $compressed = gzencode($encoded, 6);
            $spanCount = 0;
            foreach (($payload['resourceSpans'] ?? []) as $resource) {
                foreach (($resource['scopeSpans'] ?? []) as $scope) {
                    $spanCount += count($scope['spans'] ?? []);
                }
            }

            $report = [
                'mode' => 'preview',
                'networkRequestMade' => false,
                'signal' => 'traces',
                'configuredSampleRate' => $this->sampleRate,
                'sampled' => true,
                'spanCount' => $spanCount,
                'jsonBytes' => strlen($encoded),
                'gzipBytes' => $compressed === false ? 0 : strlen($compressed),
                'redactionPolicyApplied' => [
                    'url query values and URL credentials',
                    'sensitive headers and credentials',
                    'request and response bodies',
                    'exception messages and stack traces',
                    'SQL binding values',
                ],
                'payload' => $payload,
            ];
            $this->reports[] = $report;
            if (count($this->reports) > 16) {
                array_shift($this->reports);
            }

            if (\is_callable($this->writer)) {
                ($this->writer)(json_encode(
                    $report,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
                ));
            }
        } catch (\Throwable) {
            // Preview retains the SDK's never-throw guarantee.
        }
    }
}
