<?php

declare(strict_types=1);

namespace Restlytics\Laravel\Otlp;

use Restlytics\Laravel\Span;

/**
 * Builds the top-level OTLP/JSON ExportTraceServiceRequest body.
 *
 * Shape (matches packages/contract ExportTraceServiceRequest exactly):
 *   { "resourceSpans": [ {
 *       "resource":   { "attributes": [ ...resource KVs... ] },
 *       "scopeSpans": [ { "scope": {"name": "restlytics-laravel"}, "spans": [ ... ] } ]
 *   } ] }
 *
 * The resource attributes carry service identity + SDK identity; the spans carry
 * the per-request work. We emit a single resourceSpans/scopeSpans envelope because
 * every span in one request shares the same resource.
 */
final class Payload
{
    /** Stable identifiers for the SDK, surfaced as resource attributes and the scope name. */
    public const SDK_NAME = 'restlytics-laravel';
    public const SDK_LANGUAGE = 'php';
    public const SDK_VERSION = '0.1.2';

    /**
     * @param list<Span> $spans
     * @return array<string, mixed>
     */
    public static function build(string $serviceName, string $environment, array $spans): array
    {
        $otlpSpans = [];
        foreach ($spans as $span) {
            $otlpSpans[] = $span->toOtlpArray();
        }

        return [
            'resourceSpans' => [
                [
                    'resource' => [
                        'attributes' => self::resourceAttributes($serviceName, $environment),
                    ],
                    'scopeSpans' => [
                        [
                            'scope' => ['name' => self::SDK_NAME],
                            'spans' => $otlpSpans,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return list<array{key: string, value: array{stringValue: string}}>
     */
    private static function resourceAttributes(string $serviceName, string $environment): array
    {
        return [
            self::stringAttr('service.name', $serviceName),
            self::stringAttr('deployment.environment', $environment),
            self::stringAttr('telemetry.sdk.name', self::SDK_NAME),
            self::stringAttr('telemetry.sdk.language', self::SDK_LANGUAGE),
            self::stringAttr('telemetry.sdk.version', self::SDK_VERSION),
        ];
    }

    /**
     * @return array{key: string, value: array{stringValue: string}}
     */
    private static function stringAttr(string $key, string $value): array
    {
        return ['key' => $key, 'value' => ['stringValue' => $value]];
    }
}
