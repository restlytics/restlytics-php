<?php

declare(strict_types=1);

namespace Restlytics\Laravel\Tests;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\TestCase;
use Restlytics\Laravel\Exporter;
use Restlytics\Laravel\RestlyticsServiceProvider;

final class ConfiguredRecordingExporter implements Exporter
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

final class ConfiguredExporterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('customer.telemetry.exporter', ConfiguredRecordingExporter::class);
    }
}

final class ConfiguredExporterFrameworkTest extends TestCase
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [ConfiguredExporterServiceProvider::class, RestlyticsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('restlytics.key', '');
        $app['config']->set('restlytics.transport', 'customer.telemetry.exporter');
        $app['config']->set('restlytics.ignore_paths', []);
        $app['config']->set('restlytics.logs', true);
    }

    protected function defineRoutes($router): void
    {
        self::assertInstanceOf(Router::class, $router);
        $router->get('/configured-exporter', static function () {
            Log::warning('configured exporter log');

            return response('ok');
        });
    }

    public function test_bound_service_id_enables_export_without_a_restlytics_key(): void
    {
        $this->get('/configured-exporter')->assertOk();

        $exporter = $this->app->make('customer.telemetry.exporter');
        self::assertInstanceOf(ConfiguredRecordingExporter::class, $exporter);
        self::assertCount(1, $exporter->traces);
        self::assertCount(1, $exporter->logs);
    }
}
