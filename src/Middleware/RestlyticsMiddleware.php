<?php

declare(strict_types=1);

namespace Restlytics\Laravel\Middleware;

use Closure;
use Illuminate\Http\Request;
use Restlytics\Laravel\Span;
use Restlytics\Laravel\Tracer;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global terminable middleware that owns the root SERVER span.
 *
 * Why a *terminable* middleware: `handle()` opens the span as early as possible,
 * then `terminate($request, $response)` runs AFTER the response has been sent to
 * the client (under PHP-FPM, `fastcgi_finish_request()` flushes first). That means
 * all of our work — closing the span, computing self-time, gzipping, and the cURL
 * POST — happens off the critical path and adds ZERO latency to the user's request.
 *
 * `handle()` is intentionally tiny so even the sampled-out path is cheap.
 */
final class RestlyticsMiddleware
{
    /**
     * @param list<string> $ignorePaths request paths (glob-style) to skip entirely
     */
    public function __construct(
        private readonly Tracer $tracer,
        private readonly array $ignorePaths = [],
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        // Skip ignored paths (health checks, the ingest's own traffic, etc.) before
        // doing any work so they never even open a span.
        if (! $this->shouldTrace($request)) {
            return $next($request);
        }

        // Continue an incoming distributed trace if the upstream sent traceparent.
        $traceparent = $request->headers->get('traceparent');

        // Provisional name; the real http.route template isn't known until routing
        // has resolved, so we finalize the name + route attribute in terminate().
        $this->tracer->startServerSpan($request->getMethod() . ' ' . $request->getPathInfo(), $traceparent);

        return $next($request);
    }

    /**
     * Runs after the response is flushed. Close the span, set the HTTP attributes
     * (route TEMPLATE, method, status), mark errors, and flush the OTLP batch.
     *
     * Wrapped so a bug in our own instrumentation can never break a served request.
     */
    public function terminate(Request $request, Response $response): void
    {
        try {
            $root = $this->tracer->rootSpan();
            if ($root === null) {
                return; // not sampled / ignored
            }

            // http.route MUST be the TEMPLATE (e.g. /users/{id}), never the raw URL,
            // so high-cardinality ids don't explode the grouping. Fall back to the
            // path only if routing didn't resolve (404s, etc.).
            $route = $request->route();
            $template = ($route !== null && method_exists($route, 'uri'))
                ? '/' . ltrim($route->uri(), '/')
                : $request->getPathInfo();

            $method = $request->getMethod();
            $status = $response->getStatusCode();

            $root->setName($method . ' ' . $template);
            $root->setString('http.request.method', $method);
            $root->setString('http.route', $template);
            $root->setInt('http.response.status_code', $status);

            // Crash & error detection: 5xx (and unset-status) become ERROR. An
            // exception handler elsewhere may already have set a richer message.
            if ($status >= 500) {
                if ($root->statusCode() !== Span::STATUS_ERROR) {
                    $root->setStatus(Span::STATUS_ERROR, 'HTTP ' . $status);
                }
            } elseif ($root->statusCode() === Span::STATUS_UNSET) {
                $root->setStatus(Span::STATUS_OK);
            }

            $this->tracer->finishServerSpan();
        } catch (\Throwable) {
            // Never let telemetry break the host app. Best-effort cleanup of state.
            try {
                $this->tracer->reset();
            } catch (\Throwable) {
                // give up silently
            }
        }
    }

    private function shouldTrace(Request $request): bool
    {
        $path = '/' . ltrim($request->getPathInfo(), '/');
        foreach ($this->ignorePaths as $pattern) {
            $pattern = '/' . ltrim((string) $pattern, '/');
            // Support trailing wildcards like /telescope* and exact matches.
            if ($pattern === $path || \fnmatch($pattern, $path)) {
                return false;
            }
        }

        return true;
    }
}
