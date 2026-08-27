<?php

declare(strict_types=1);

namespace Restlytics\Laravel\Tests;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\TestCase;
use Restlytics\Laravel\Exporter;
use Restlytics\Laravel\RestlyticsServiceProvider;

final class RecordingExporter implements Exporter
{
    /** @var list<array<string, mixed>> */
    public array $traces = [];

    /** @var list<array<string, mixed>> */
    public array $logs = [];

    public function exportTraces(array $payload): void
    {
        $this->traces[] = $payload;
    }

    public function exportLogs(array $payload): void
    {
        $this->logs[] = $payload;
    }
}

final class CustomerExporterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Exporter::class, RecordingExporter::class);
    }
}

final class ExporterFrameworkTest extends TestCase
{
    private const PROJECT_KEY = 'rk_custom_exporter_tenant';

    private const SECRET = 'customer-secret-never-forwarded';

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [CustomerExporterServiceProvider::class, RestlyticsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('restlytics.key', self::PROJECT_KEY);
        $app['config']->set('restlytics.service_name', 'custom-exporter-app');
        $app['config']->set('restlytics.env', 'test');
        $app['config']->set('restlytics.ignore_paths', []);
        $app['config']->set('restlytics.logs', true);
        $app['config']->set('restlytics.logs_min_severity', 13);
    }

    protected function defineRoutes($router): void
    {
        self::assertInstanceOf(Router::class, $router);
        $router->get('/custom/{id}', static function (string $id) {
            Log::warning('authorization=Bearer '.self::SECRET, [
                'safe.order_id' => $id,
                'token' => self::SECRET,
            ]);

            return response()->json(['id' => $id]);
        });
    }

    public function test_container_exporter_receives_redacted_production_trace_and_log_payloads(): void
    {
        $this->get('/custom/42?token='.self::SECRET)->assertOk();

        $exporter = $this->app->make(Exporter::class);
        self::assertInstanceOf(RecordingExporter::class, $exporter);
        self::assertCount(1, $exporter->traces);
        self::assertCount(1, $exporter->logs);
        self::assertArrayHasKey('resourceSpans', $exporter->traces[0]);
        self::assertArrayHasKey('resourceLogs', $exporter->logs[0]);

        $traceResource = $this->attributes($exporter->traces[0]['resourceSpans'][0]['resource']);
        $logResource = $this->attributes($exporter->logs[0]['resourceLogs'][0]['resource']);
        self::assertSame(['stringValue' => 'custom-exporter-app'], $traceResource['service.name']);
        self::assertSame(['stringValue' => 'custom-exporter-app'], $logResource['service.name']);

        $encoded = json_encode([$exporter->traces, $exporter->logs], JSON_THROW_ON_ERROR);
        self::assertStringContainsString('[REDACTED]', $encoded);
        self::assertStringNotContainsString(self::PROJECT_KEY, $encoded);
        self::assertStringNotContainsString(self::SECRET, $encoded);
        self::assertStringNotContainsString('tenant', strtolower($encoded));
    }

    /**
     * @param  array<string, mixed>  $resource
     * @return array<string, array<string, mixed>>
     */
    private function attributes(array $resource): array
    {
        $attributes = [];
        foreach ($resource['attributes'] ?? [] as $attribute) {
            $attributes[$attribute['key']] = $attribute['value'];
        }

        return $attributes;
    }
}
