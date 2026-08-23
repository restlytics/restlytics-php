<?php

declare(strict_types=1);

namespace Restlytics\Laravel\Tests;

use Illuminate\Routing\Router;
use Orchestra\Testbench\TestCase;
use Restlytics\Laravel\RestlyticsServiceProvider;
use Restlytics\Laravel\Transport\PreviewTransport;
use Restlytics\Laravel\Transport\Transport;

final class PreviewFrameworkTest extends TestCase
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [RestlyticsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('restlytics.key', '');
        $app['config']->set('restlytics.transport', 'preview');
        $app['config']->set('restlytics.sample_rate', 1.0);
        $app['config']->set('restlytics.ignore_paths', []);
    }

    protected function defineRoutes($router): void
    {
        self::assertInstanceOf(Router::class, $router);
        $router->get('/preview/{id}', static fn (string $id) => response()->json(['id' => $id]));
    }

    public function test_preview_instruments_a_real_request_without_an_ingest_key(): void
    {
        $this->get('/preview/42?token=customer-secret')->assertOk();

        $transport = $this->app->make(Transport::class);
        self::assertInstanceOf(PreviewTransport::class, $transport);
        self::assertCount(1, $transport->reports);
        self::assertFalse($transport->reports[0]['networkRequestMade']);
        self::assertStringNotContainsString(
            'customer-secret',
            json_encode($transport->reports[0], JSON_THROW_ON_ERROR),
        );
    }
}
