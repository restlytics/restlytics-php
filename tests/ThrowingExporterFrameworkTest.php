<?php

declare(strict_types=1);

namespace Restlytics\Laravel\Tests;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Log;
use Orchestra\Testbench\TestCase;
use Restlytics\Laravel\BackgroundWork;
use Restlytics\Laravel\Exporter;
use Restlytics\Laravel\RestlyticsServiceProvider;

final class ThrowingExporter implements Exporter
{
    public static int $traceAttempts = 0;

    public static int $logAttempts = 0;

    public function exportTraces(array $payload): void
    {
        self::$traceAttempts++;

        throw new \RuntimeException('customer transport is offline');
    }

    public function exportLogs(array $payload): void
    {
        self::$logAttempts++;

        throw new \RuntimeException('customer transport is offline');
    }
}

final class ThrowingExporterFrameworkTest extends TestCase
{
    protected function setUp(): void
    {
        ThrowingExporter::$traceAttempts = 0;
        ThrowingExporter::$logAttempts = 0;

        parent::setUp();
    }

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [RestlyticsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('restlytics.key', 'rk_failure_isolation');
        $app['config']->set('restlytics.transport', ThrowingExporter::class);
        $app['config']->set('restlytics.ignore_paths', []);
        $app['config']->set('restlytics.logs', true);
        $app['config']->set('restlytics.logs_min_severity', 13);
    }

    protected function defineRoutes($router): void
    {
        self::assertInstanceOf(Router::class, $router);
        $router->get('/safe', static function () {
            Log::error('the exporter will fail');

            return response('host-response', 200);
        });
    }

    public function test_exporter_failures_do_not_escape_request_job_command_or_schedule_lifecycles(): void
    {
        $this->get('/safe')->assertOk()->assertSeeText('host-response');

        $work = $this->app->make(BackgroundWork::class);
        self::assertSame('job-result', $work->job(
            'App\\Jobs\\SafeJob',
            'redis',
            'default',
            static fn (): string => 'job-result',
        ));
        self::assertSame(0, $work->command('safe:command', static fn (): int => 0));
        self::assertSame('schedule-result', $work->schedule(
            'safe-schedule',
            '* * * * *',
            static fn (): string => 'schedule-result',
        ));

        self::assertSame(4, ThrowingExporter::$traceAttempts);
        self::assertSame(1, ThrowingExporter::$logAttempts);
    }
}
