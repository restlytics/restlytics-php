<?php

declare(strict_types=1);

namespace Restlytics\Laravel\Otlp;

/** Builds an OTLP/JSON ExportLogsServiceRequest using the trace resource verbatim. */
final class LogPayload
{
    /**
     * @param  list<array<string, mixed>>  $records
     * @return array<string, mixed>
     */
    public static function build(string $serviceName, string $environment, array $records): array
    {
        return [
            'resourceLogs' => [
                [
                    'resource' => [
                        'attributes' => Payload::resourceAttributes($serviceName, $environment),
                    ],
                    'scopeLogs' => [
                        [
                            'scope' => [
                                'name' => Payload::SDK_NAME,
                                'version' => Payload::SDK_VERSION,
                            ],
                            'logRecords' => $records,
                        ],
                    ],
                ],
            ],
        ];
    }
}
