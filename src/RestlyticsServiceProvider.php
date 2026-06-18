<?php

declare(strict_types=1);

namespace Restlytics\Laravel;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Restlytics\Laravel\Middleware\RestlyticsMiddleware;
use Restlytics\Laravel\Support\Sql;
use Restlytics\Laravel\Transport\CurlTransport;
use Restlytics\Laravel\Transport\LogTransport;
use Restlytics\Laravel\Transport\NullTransport;
use Restlytics\Laravel\Transport\Transport;

/**
 * Wires the SDK into a Laravel app: config, the Tracer/Transport singletons, the
 * global terminable middleware, and the DB/HTTP/cache instruments.
 *
 * Design goals: 5-minute install (zero code changes — `extra.laravel.providers`
 * auto-discovers this), and Octane-safety (resolve at call-time, reset per request,
 * cap the buffer).
 */
final class RestlyticsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/restlytics.php', 'restlytics');

        // Transport is a singleton; chosen by config('restlytics.transport').
        $this->app->singleton(Transport::class, function ($app): Transport {
            return $this->makeTransport($app);
        });

        // Tracer is a singleton (reused across Octane requests) and reset per request.
        $this->app->singleton(Tracer::class, function ($app): Tracer {
            $config = $app['config'];

            return new Tracer(
                transport: $app->make(Transport::class),
                serviceName: (string) $config->get('restlytics.service_name', 'laravel'),
                environment: (string) $config->get('restlytics.env', 'production'),
                sampleRate: (float) $config->get('restlytics.sample_rate', 1.0),
                maxSpans: (int) $config->get('restlytics.max_spans', 2000),
            );
        });
    }

    public function boot(): void
    {
        // Allow `php artisan vendor:publish --tag=restlytics-config`.
        $this->publishes([
            __DIR__ . '/../config/restlytics.php' => $this->app->configPath('restlytics.php'),
        ], 'restlytics-config');

        // Disabled (no key) → install nothing. The package stays inert and free.
        if (! $this->enabled()) {
            return;
        }

        $this->registerMiddleware();
        $this->registerDatabaseListener();
        $this->registerHttpListener();
        $this->registerCacheListener();
        $this->registerOctaneResetHooks();
    }

    private function enabled(): bool
    {
        return (string) $this->app['config']->get('restlytics.key', '') !== '';
    }

    /**
     * Append the terminable middleware to the GLOBAL stack so every request is
     * traced without the user touching their Kernel. Appending (not prepending)
     * keeps it outermost-last so it brackets the full request lifecycle.
     */
    private function registerMiddleware(): void
    {
        $tracer = $this->app->make(Tracer::class);
        $ignore = (array) $this->app['config']->get('restlytics.ignore_paths', []);

        // Bind a concrete, pre-configured instance so the framework reuses ours
        // (with ignore paths + the tracer) rather than newing up an empty one.
        $this->app->singleton(RestlyticsMiddleware::class, static function () use ($tracer, $ignore): RestlyticsMiddleware {
            return new RestlyticsMiddleware($tracer, $ignore);
        });

        if ($this->app->bound(HttpKernel::class)) {
            $kernel = $this->app->make(HttpKernel::class);
            if (method_exists($kernel, 'pushMiddleware')) {
                $kernel->pushMiddleware(RestlyticsMiddleware::class);
            }
        }
    }

    /**
     * DB child spans. QueryExecuted fires AFTER a query runs and reports its
     * elapsed time in milliseconds, so we BACK-DATE the start (now − elapsed).
     *
     * Redaction: we send only the NORMALIZED statement (db.query.summary) — a
     * literal-free template that doubles as the N+1 grouping key — plus a binding
     * COUNT. Raw text (db.query.text) is sent only when capture_sql is on, capped.
     * Binding VALUES are never sent.
     */
    private function registerDatabaseListener(): void
    {
        if (! (bool) $this->app['config']->get('restlytics.instruments.db', true)) {
            return;
        }

        $tracer = $this->app->make(Tracer::class);
        $captureSql = (bool) $this->app['config']->get('restlytics.capture_sql', false);

        DB::listen(static function (QueryExecuted $query) use ($tracer, $captureSql): void {
            if (! $tracer->isSampled()) {
                return;
            }

            $tracer->incrementDbQueryCount();

            // $query->time is elapsed milliseconds (float). Back-date the start.
            $endNs = $tracer->nowNs();
            $startNs = $endNs - (int) round(((float) $query->time) * 1_000_000);

            $summary = Sql::normalize((string) $query->sql);

            $span = $tracer->addChildSpan('db.query', $startNs, $endNs);
            if ($span === null) {
                return;
            }

            $span
                ->setString('db.system.name', (string) $query->connection->getDriverName())
                ->setString('db.query.summary', $summary)
                ->setInt('restlytics.bindings_count', \count((array) $query->bindings))
                ->setString('restlytics.category', 'db');

            // A short, human-readable span name from the leading SQL keyword.
            if (preg_match('/^\s*(\w+)/', $summary, $m) === 1) {
                $span->setName('db ' . strtolower($m[1]));
            }

            if ($captureSql) {
                // Raw text may carry PII; cap hard at 2048 chars (contract max).
                $span->setString('db.query.text', mb_substr((string) $query->sql, 0, 2048));
            }
        });
    }

    /**
     * Outbound HTTP child spans via the Laravel HTTP client's global Guzzle
     * middleware (`on_stats`). Client events lack parent correlation, so these are
     * best-effort children of whatever root span is active when the call returns.
     *
     * Redaction: url.full has its query string scrubbed; no headers/bodies sent.
     */
    private function registerHttpListener(): void
    {
        if (! (bool) $this->app['config']->get('restlytics.instruments.http', true)) {
            return;
        }
        if (! class_exists(Http::class)) {
            return;
        }

        $tracer = $this->app->make(Tracer::class);
        $redactKeys = (array) $this->app['config']->get('restlytics.redaction.query_keys', []);

        // globalRequestMiddleware/globalResponseMiddleware exist on Laravel 10.14+.
        if (! method_exists(Http::class, 'globalResponseMiddleware')) {
            return;
        }

        // Use a global response middleware: it sees the final response and, via the
        // transfer stats, the timing. We compute the span on the way back.
        Http::globalResponseMiddleware(static function ($response) use ($tracer, $redactKeys) {
            try {
                if (! $tracer->isSampled()) {
                    return $response;
                }

                $stats = method_exists($response, 'transferStats') ? $response->transferStats() : null;
                if ($stats === null) {
                    return $response;
                }

                $uri = $stats->getEffectiveUri();
                $totalSeconds = (float) $stats->getTransferTime();

                $endNs = $tracer->nowNs();
                $startNs = $endNs - (int) round($totalSeconds * 1_000_000_000);

                $host = $uri->getHost();
                $span = $tracer->addChildSpan('http ' . $host, $startNs, $endNs);
                if ($span === null) {
                    return $response;
                }

                $request = $stats->getRequest();
                $span
                    ->setString('http.request.method', $request->getMethod())
                    ->setString('url.full', self::redactUrl((string) $uri, $redactKeys))
                    ->setString('server.address', $host)
                    ->setInt('http.response.status_code', $response->getStatusCode())
                    ->setString('restlytics.category', 'http');
            } catch (\Throwable) {
                // best-effort: outbound HTTP instrumentation never breaks the call
            }

            return $response;
        });
    }

    /**
     * Cache hit/miss child spans (best-effort, kept light). Cache events don't
     * carry timing, so these are zero-duration markers categorized as 'cache'.
     */
    private function registerCacheListener(): void
    {
        if (! (bool) $this->app['config']->get('restlytics.instruments.cache', true)) {
            return;
        }

        $tracer = $this->app->make(Tracer::class);

        // String event names avoid importing classes that may differ across versions.
        Event::listen('Illuminate\\Cache\\Events\\CacheHit', static function ($event) use ($tracer): void {
            self::recordCacheEvent($tracer, 'cache hit', $event);
        });
        Event::listen('Illuminate\\Cache\\Events\\CacheMissed', static function ($event) use ($tracer): void {
            self::recordCacheEvent($tracer, 'cache miss', $event);
        });
    }

    private static function recordCacheEvent(Tracer $tracer, string $name, object $event): void
    {
        try {
            if (! $tracer->isSampled()) {
                return;
            }
            $now = $tracer->nowNs();
            $span = $tracer->addChildSpan($name, $now, $now);
            $span?->setString('restlytics.category', 'cache');
        } catch (\Throwable) {
            // best-effort
        }
    }

    /**
     * Octane reuses workers across requests, so reset the Tracer when a new request
     * is received (and tear down on worker stop). Under FPM these events never fire,
     * which is fine — the middleware already resets on each handle().
     */
    private function registerOctaneResetHooks(): void
    {
        $tracer = $this->app->make(Tracer::class);

        foreach (['Laravel\\Octane\\Events\\RequestReceived', 'Laravel\\Octane\\Events\\TaskReceived'] as $eventClass) {
            Event::listen($eventClass, static function () use ($tracer): void {
                $tracer->reset();
            });
        }
    }

    /**
     * Build the configured transport. Resolves once (singleton); the heavy work
     * (cURL) is deferred to send() and the logger is resolved at call-time.
     */
    private function makeTransport(\Illuminate\Contracts\Foundation\Application $app): Transport
    {
        $config = $app['config'];
        $driver = (string) $config->get('restlytics.transport', 'curl');

        return match ($driver) {
            'null', 'none' => new NullTransport(),
            'log' => new LogTransport(static function (string $json) use ($app): void {
                // Resolve the logger lazily so we don't bind a stale instance.
                if ($app->bound('log')) {
                    $app->make('log')->debug('restlytics payload', ['otlp' => $json]);
                }
            }),
            default => new CurlTransport(
                ingestUrl: (string) $config->get('restlytics.ingest_url', ''),
                key: (string) $config->get('restlytics.key', ''),
                timeoutMs: (int) $config->get('restlytics.timeout_ms', 2000),
                onError: static function (string $message) use ($app): void {
                    // Surface transport errors at debug level only — never noisy,
                    // never thrown.
                    if ($app->bound('log')) {
                        $app->make('log')->debug($message);
                    }
                },
            ),
        };
    }

    /**
     * Strip sensitive keys from a URL's query string for url.full. Keeps the path
     * and host (needed for grouping) but never leaks tokens/secrets.
     *
     * @param list<string> $redactKeys
     */
    private static function redactUrl(string $url, array $redactKeys): string
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['query'])) {
            return $url;
        }

        parse_str($parts['query'], $params);
        $lowerRedact = array_map('strtolower', $redactKeys);
        foreach (array_keys($params) as $key) {
            if (\in_array(strtolower((string) $key), $lowerRedact, true)) {
                $params[$key] = 'REDACTED';
            }
        }

        $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '';
        $query = $params !== [] ? '?' . http_build_query($params) : '';

        return $scheme . $host . $port . $path . $query;
    }
}
