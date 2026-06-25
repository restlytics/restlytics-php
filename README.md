# restlytics — Laravel SDK

Zero-config performance + error tracing for Laravel, shipped to [restlytics](https://restlytics.com) in OTLP/JSON.

- **5-minute install** — auto-discovered service provider, no code changes.
- **Pure PHP** — no PECL/OpenTelemetry extension required. Just cURL + zlib (bundled with PHP).
- **Zero added latency** — spans are flushed *after* the HTTP response is sent (terminable middleware + `fastcgi_finish_request`), fire-and-forget over cURL with a hard timeout.
- **Safe by default** — head-based sampling, SQL normalized to literal-free templates, bindings never sent, query strings scrubbed, no request/response bodies.

> **This is the canonical, open-source repository for the restlytics Laravel SDK** — published to Packagist as `restlytics/laravel`. Open issues and pull requests here. It conforms to the cross-language restlytics wire contract, so the ingestion service accepts it identically to every other restlytics SDK.

---

## Install

```bash
composer require restlytics/laravel
```

That's it — the `RestlyticsServiceProvider` is auto-discovered. Add your keys to `.env`:

```dotenv
RESTLYTICS_KEY=your-project-ingest-key
RESTLYTICS_INGEST_URL=https://ingest.restlytics.com
RESTLYTICS_ENV=production
```

Until `RESTLYTICS_KEY` is set the SDK stays completely inert (no spans built, no requests made), so it's safe to deploy before you've provisioned a key.

Publish the config to tweak defaults:

```bash
php artisan vendor:publish --tag=restlytics-config
```

### `.env` reference

| Variable | Default | Purpose |
| --- | --- | --- |
| `RESTLYTICS_KEY` | `""` | Project ingest key (sent as `X-Restlytics-Key`). Empty = disabled. |
| `RESTLYTICS_INGEST_URL` | `https://ingest.restlytics.com` | Ingest base URL; SDK POSTs to `{url}/v1/traces`. |
| `RESTLYTICS_ENV` | `APP_ENV` | `deployment.environment` resource attribute. |
| `RESTLYTICS_SERVICE_NAME` | `APP_NAME` | `service.name` resource attribute. |
| `RESTLYTICS_SAMPLE_RATE` | `1.0` | Head-based trace sampling, `0.0`–`1.0`. |
| `RESTLYTICS_TRANSPORT` | `curl` | `curl` (prod), `log` (dev), `null` (off/tests). |
| `RESTLYTICS_TIMEOUT_MS` | `2000` | Hard cap on the send. |
| `RESTLYTICS_CAPTURE_SQL` | `false` | Send raw SQL text (capped 2048). Off = template only. |
| `RESTLYTICS_INSTRUMENT_DB` / `_HTTP` / `_CACHE` | `true` | Per-instrument toggles. |
| `RESTLYTICS_MAX_SPANS` | `2000` | Per-request span buffer cap. |

---

## How it works

1. **Root request span** — a global *terminable* middleware opens a `SERVER` span in `handle()`
   and finalizes it in `terminate($request, $response)`, which PHP-FPM runs **after** the response
   has been flushed to the client. So closing the span, computing self-time, gzipping, and the
   cURL POST all happen off the request's critical path.
2. **DB spans** — `DB::listen()` turns each `QueryExecuted` into a `CLIENT` span. The statement is
   normalized to a literal-free template (`SELECT * FROM users WHERE id = ?`) used both as the
   N+1 grouping key and to keep PII off the wire. We record the binding **count**, never values.
3. **Outbound HTTP spans** — a global Laravel HTTP-client middleware captures method, host,
   redacted `url.full`, status, and timing for each call.
4. **Cache spans** — best-effort hit/miss markers via cache events.
5. **Self-time** — child spans are interval-unioned per category (db / http / cache) so overlapping
   work isn't double-counted; `app` self-time is the root's exclusive time. Emitted as
   `restlytics.self_ns.*` on the root span.
6. **Errors** — 5xx responses and uncaught exceptions set the span status to `ERROR (2)`.

Timing uses the monotonic clock (`hrtime(true)`) for durations, anchored to one wall-clock reading
for absolute epoch-nanosecond timestamps — durations stay correct across NTP adjustments.

### Octane

The `Tracer` is a singleton reused across requests in a long-lived worker. It resets its state on
each request (middleware `handle()` + Octane `RequestReceived`), resolves services at call-time, and
caps the in-request buffer, so spans never leak between requests and memory stays bounded.

---

## Trust & redaction

restlytics is built to be safe to run in production against real traffic:

- **Fire-and-forget, never fatal.** Every transport/instrument path is wrapped; telemetry can never
  throw into — or slow — your app. A slow/unreachable ingest endpoint is bounded by a short timeout.
- **No binding values.** SQL is normalized to a template; only a binding *count* is sent.
- **No raw SQL** unless you explicitly set `RESTLYTICS_CAPTURE_SQL=true` (then capped at 2048 chars).
- **Scrubbed URLs.** `url.full` query strings have sensitive keys (token, password, secret, …)
  redacted. The `http.route` attribute is always the **template** (`/users/{id}`), never the raw URL.
- **No bodies / headers.** Request and response bodies and headers are never captured.
- **Sampling.** Lower `RESTLYTICS_SAMPLE_RATE` to capture a fraction of traffic.

---

## Local development

Set `RESTLYTICS_TRANSPORT=log` to dump the OTLP payload to your Laravel log instead of the network,
or `RESTLYTICS_TRANSPORT=null` to disable delivery while keeping instrumentation (useful in tests).

## License

MIT © restlytics. See [LICENSE](LICENSE).
