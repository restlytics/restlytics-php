<?php

declare(strict_types=1);

namespace Restlytics\Laravel\Tests;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Log;
use Orchestra\Testbench\TestCase;
use Restlytics\Laravel\RestlyticsServiceProvider;

final class FrameworkAppTest extends TestCase
{
    private const PROJECT_KEY = 'rk_project_alpha';

    private const SECRET = 'customer-secret-must-not-leave-the-app';

    /** @var resource|null */
    private $serverProcess = null;

    private string $capturePath;

    private string $statusPath;

    private int $port;

    protected function setUp(): void
    {
        $this->capturePath = tempnam(sys_get_temp_dir(), 'restlytics-capture-') ?: '';
        $this->statusPath = tempnam(sys_get_temp_dir(), 'restlytics-status-') ?: '';
        self::assertNotSame('', $this->capturePath);
        self::assertNotSame('', $this->statusPath);
        file_put_contents($this->statusPath, '202');

        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($socket, $error ?: 'could not reserve test port');
        $address = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $this->port = (int) substr($address, (int) strrpos($address, ':') + 1);

        $router = __DIR__.'/Fixtures/ingest-router.php';
        $environment = array_merge($_ENV, [
            'RESTLYTICS_TEST_CAPTURE_PATH' => $this->capturePath,
            'RESTLYTICS_TEST_STATUS_PATH' => $this->statusPath,
        ]);
        $pipes = [];
        $this->serverProcess = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:'.$this->port, $router],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']],
            $pipes,
            __DIR__,
            $environment,
        );
        self::assertIsResource($this->serverProcess);
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }
        $this->waitForServer();

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_resource($this->serverProcess)) {
            proc_terminate($this->serverProcess);
            proc_close($this->serverProcess);
        }
        @unlink($this->capturePath);
        @unlink($this->statusPath);
    }

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [RestlyticsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('restlytics.key', self::PROJECT_KEY);
        $app['config']->set('restlytics.ingest_url', 'http://127.0.0.1:'.$this->port);
        $app['config']->set('restlytics.service_name', 'laravel-beta-app');
        $app['config']->set('restlytics.env', 'test');
        $app['config']->set('restlytics.timeout_ms', 300);
        $app['config']->set('restlytics.ignore_paths', []);
        $app['config']->set('restlytics.logs', true);
        $app['config']->set('restlytics.logs_min_severity', 13);
    }

    protected function defineRoutes($router): void
    {
        self::assertInstanceOf(Router::class, $router);
        $router->get('/orders/{id}', static function (string $id) {
            if ($id === '42') {
                Log::warning('checkout token='.self::SECRET.' buyer@example.test', [
                    'safe.order_id' => $id,
                    'authorization' => 'Bearer '.self::SECRET,
                ]);
            }

            return response()->json(['id' => $id]);
        });
        $router->get('/fail/{id}', static fn () => response('unavailable', 503));
    }

    public function test_real_laravel_app_emits_tenant_safe_otlp_and_survives_ingest_failure(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.self::SECRET,
            'Cookie' => 'session='.self::SECRET,
            'traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
        ])->get('/orders/42?token='.self::SECRET);
        $response->assertOk();

        $first = $this->capture(0);
        self::assertSame('/v1/traces', $first['path']);
        self::assertSame(self::PROJECT_KEY, $first['key']);
        self::assertSame('gzip', $first['encoding']);
        $payload = $this->payload($first);
        $root = $payload['resourceSpans'][0]['scopeSpans'][0]['spans'][0];
        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $root['traceId']);
        self::assertSame('00f067aa0ba902b7', $root['parentSpanId']);
        self::assertSame(['stringValue' => '/orders/{id}'], $this->attributes($root)['http.route']);
        self::assertStringNotContainsString(self::PROJECT_KEY, json_encode($payload, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString(self::SECRET, json_encode($payload, JSON_THROW_ON_ERROR));

        $logCapture = $this->capture(1);
        self::assertSame('/v1/logs', $logCapture['path']);
        self::assertSame(self::PROJECT_KEY, $logCapture['key']);
        $logPayload = $this->payload($logCapture);
        $record = $logPayload['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0];
        self::assertSame($root['traceId'], $record['traceId']);
        self::assertSame($root['spanId'], $record['spanId']);
        self::assertSame(13, $record['severityNumber']);
        $encodedLog = json_encode($logPayload, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('[REDACTED]', $encodedLog);
        self::assertStringNotContainsString(self::SECRET, $encodedLog);
        self::assertStringNotContainsString('buyer@example.test', $encodedLog);
        self::assertStringNotContainsString('authorization', $encodedLog);

        file_put_contents($this->statusPath, '503');
        $this->get('/orders/43')->assertOk();
        $this->capture(2); // transport reached the failing ingest and swallowed its response

        file_put_contents($this->statusPath, '202');
        $this->get('/fail/44')->assertStatus(503);
        $failed = $this->payload($this->capture(3));
        $failedRoot = $failed['resourceSpans'][0]['scopeSpans'][0]['spans'][0];
        self::assertSame(2, $failedRoot['status']['code']);
        self::assertSame(['stringValue' => '/fail/{id}'], $this->attributes($failedRoot)['http.route']);
    }

    private function waitForServer(): void
    {
        $deadline = microtime(true) + 2.0;
        do {
            $socket = @fsockopen('127.0.0.1', $this->port, $errno, $error, 0.05);
            if (is_resource($socket)) {
                fclose($socket);

                return;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);

        self::fail('timed out starting deployed-compatible ingest server');
    }

    /** @return array{path:string,key:string,encoding:string,body:string} */
    private function capture(int $index): array
    {
        $deadline = microtime(true) + 2.0;
        do {
            $lines = file($this->capturePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            if (isset($lines[$index])) {
                /** @var array{path:string,key:string,encoding:string,body:string} $capture */
                $capture = json_decode($lines[$index], true, flags: JSON_THROW_ON_ERROR);

                return $capture;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);

        self::fail('timed out waiting for ingest request '.($index + 1));
    }

    /** @param array{body:string} $capture
     * @return array<string, mixed>
     */
    private function payload(array $capture): array
    {
        $compressed = base64_decode($capture['body'], true);
        self::assertIsString($compressed);
        $json = gzdecode($compressed);
        self::assertIsString($json);

        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $span
     * @return array<string, mixed>
     */
    private function attributes(array $span): array
    {
        $attributes = [];
        foreach ($span['attributes'] ?? [] as $attribute) {
            $attributes[$attribute['key']] = $attribute['value'];
        }

        return $attributes;
    }
}
