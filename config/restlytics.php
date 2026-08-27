<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Ingest key
    |--------------------------------------------------------------------------
    | Your project's ingest key. Sent as the `X-Restlytics-Key` header. When
    | empty the SDK quietly disables itself (no spans built, no requests made),
    | so it's safe to ship the package without a key configured yet.
    */
    'key' => env('RESTLYTICS_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Ingest URL
    |--------------------------------------------------------------------------
    | Base URL of the restlytics ingestion service. The SDK POSTs to
    | `{ingest_url}/v1/traces`. No trailing slash required.
    */
    'ingest_url' => env('RESTLYTICS_INGEST_URL', 'https://ingest.restlytics.com'),

    /*
    |--------------------------------------------------------------------------
    | Service name & environment (resource attributes)
    |--------------------------------------------------------------------------
    | service.name identifies this app; deployment.environment separates
    | prod/staging/etc. Defaults pull from your existing Laravel config.
    */
    'service_name' => env('RESTLYTICS_SERVICE_NAME', env('APP_NAME', 'laravel')),
    'env' => env('RESTLYTICS_ENV', env('APP_ENV', 'production')),

    /*
    |--------------------------------------------------------------------------
    | Head-based sampling rate (0.0 – 1.0)
    |--------------------------------------------------------------------------
    | Fraction of traces to capture, decided once per request from the trace id.
    | 1.0 = everything. Lower it under heavy traffic to cut volume + cost.
    */
    'sample_rate' => (float) env('RESTLYTICS_SAMPLE_RATE', 1.0),

    /*
    |--------------------------------------------------------------------------
    | Transport
    |--------------------------------------------------------------------------
    | curl  — default; gzip + fire-and-forget POST after the response is flushed.
    | preview — structured local report; never networks and needs no ingest key.
    | log   — write the OTLP payload to the Laravel log (local debugging).
    | null  — no-op (tests / disabling delivery while keeping instrumentation).
    | You may also provide a container service id or Exporter class name. The
    | recommended Laravel integration is to bind Restlytics\Laravel\Exporter in
    | your application's register() method and leave this value unchanged.
    */
    'transport' => env('RESTLYTICS_TRANSPORT', 'curl'),

    /*
    |--------------------------------------------------------------------------
    | Source-safe application logs (opt-in)
    |--------------------------------------------------------------------------
    | Native log capture is disabled by default. When enabled, records below the
    | configured OTLP severity are discarded before buffering. WARN is 13.
    */
    'logs' => (bool) env('RESTLYTICS_LOGS', false),
    'logs_min_severity' => (int) env('RESTLYTICS_LOGS_MIN_SEVERITY', 13),
    'max_logs' => (int) env('RESTLYTICS_MAX_LOGS', 256),

    /*
    |--------------------------------------------------------------------------
    | cURL transport timeout (milliseconds)
    |--------------------------------------------------------------------------
    | Hard cap on the send. Runs after the response is flushed, but we still bound
    | it so a slow/unreachable ingest endpoint can't tie up workers.
    */
    'timeout_ms' => (int) env('RESTLYTICS_TIMEOUT_MS', 2000),

    /*
    |--------------------------------------------------------------------------
    | Capture raw SQL (db.query.text)
    |--------------------------------------------------------------------------
    | OFF by default. When off, only the NORMALIZED (literal-free) statement is
    | sent as db.query.summary — never any values. Turn this on only if you
    | accept that raw SQL text (capped at 2048 chars) may carry PII. Binding
    | VALUES are NEVER sent regardless of this setting.
    */
    'capture_sql' => (bool) env('RESTLYTICS_CAPTURE_SQL', false),

    /*
    |--------------------------------------------------------------------------
    | Per-instrument toggles
    |--------------------------------------------------------------------------
    | Turn individual instruments on/off. The root SERVER span is always on when
    | the SDK is enabled; these control the child spans.
    */
    'instruments' => [
        'db' => (bool) env('RESTLYTICS_INSTRUMENT_DB', true),
        'http' => (bool) env('RESTLYTICS_INSTRUMENT_HTTP', true),
        'cache' => (bool) env('RESTLYTICS_INSTRUMENT_CACHE', true),
        'jobs' => (bool) env('RESTLYTICS_JOBS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ignore paths
    |--------------------------------------------------------------------------
    | Request paths to skip entirely (no span opened). Supports trailing `*`
    | wildcards (fnmatch). Health checks and dev tooling live here by default.
    */
    'ignore_paths' => [
        '/up',
        '/health',
        '/healthz',
        '/telescope*',
        '/horizon*',
        '/_debugbar*',
    ],

    /*
    |--------------------------------------------------------------------------
    | Redaction
    |--------------------------------------------------------------------------
    | url.full (outbound HTTP) has every query value scrubbed; this list remains
    | for backwards-compatible configuration. Request/response bodies, headers,
    | logs, exception content and binding values are never captured. This is
    | belt-and-suspenders on top of always-on SQL normalization.
    */
    'redaction' => [
        'query_keys' => ['token', 'api_key', 'apikey', 'password', 'secret', 'access_token', 'key', 'signature'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Buffer cap
    |--------------------------------------------------------------------------
    | Max child spans buffered per request. Bounds memory on pathological traces
    | (e.g. severe N+1) — extra spans past the cap are dropped, not queued.
    */
    'max_spans' => (int) env('RESTLYTICS_MAX_SPANS', 2000),
];
