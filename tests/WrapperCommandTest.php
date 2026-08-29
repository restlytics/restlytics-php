<?php

declare(strict_types=1);

namespace Restlytics\Laravel\Tests;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Restlytics\Laravel\Exporter;
use Restlytics\Laravel\RestlyticsServiceProvider;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

final class WrapperCommandExporter implements Exporter
{
    /** @var list<array<string, mixed>> */
    public array $traces = [];

    public function exportTraces(array $payload): void
    {
        $this->traces[] = $payload;
    }

    public function exportLogs(array $payload): void {}
}

final class WrapperCommandExporterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Exporter::class, WrapperCommandExporter::class);
    }
}

/**
 * Long-running Artisan commands (`queue:work`, `schedule:run`, ...) are containers
 * for other work units, not work units themselves. Opening a command root for them
 * emits a span covering the whole process lifetime whenever the wrapper does no
 * inner work — 1,440 junk `schedule:run` spans a day on a normal cron, billed by
 * byte volume. They must never open a root.
 */
final class WrapperCommandTest extends TestCase
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [WrapperCommandExporterServiceProvider::class, RestlyticsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('restlytics.key', 'rk_wrapper_command_tenant');
        $app['config']->set('restlytics.service_name', 'wrapper-command-app');
        $app['config']->set('restlytics.env', 'test');
        $app['config']->set('restlytics.sample_rate', 1.0);
    }

    /** @return list<string> */
    private function runCommand(string $name): array
    {
        $exporter = $this->app->make(Exporter::class);
        self::assertInstanceOf(WrapperCommandExporter::class, $exporter);
        $exporter->traces = [];

        $input = new ArrayInput([]);
        $output = new NullOutput;
        Event::dispatch(new CommandStarting($name, $input, $output));
        Event::dispatch(new CommandFinished($name, $input, $output, 0));

        $names = [];
        foreach ($exporter->traces as $payload) {
            foreach ($payload['resourceSpans'] ?? [] as $resourceSpan) {
                foreach ($resourceSpan['scopeSpans'] ?? [] as $scopeSpan) {
                    foreach ($scopeSpan['spans'] ?? [] as $span) {
                        $names[] = (string) ($span['name'] ?? '');
                    }
                }
            }
        }

        return $names;
    }

    /** @return list<array{string}> */
    public static function wrapperCommands(): array
    {
        return [
            'queue worker' => ['queue:work'],
            'queue listener' => ['queue:listen'],
            'scheduler tick' => ['schedule:run'],
            'scheduler worker' => ['schedule:work'],
            'horizon supervisor' => ['horizon:supervisor'],
        ];
    }

    #[DataProvider('wrapperCommands')]
    public function test_wrapper_commands_do_not_open_a_command_root(string $command): void
    {
        self::assertSame([], $this->runCommand($command), $command.' must not export a command root span');
    }

    /** @return list<array{string}> */
    public static function introspectionCommands(): array
    {
        return [
            'list' => ['list'],
            'help' => ['help'],
            'about' => ['about'],
            'inspire' => ['inspire'],
        ];
    }

    #[DataProvider('introspectionCommands')]
    public function test_introspection_commands_do_not_open_a_command_root(string $command): void
    {
        self::assertSame([], $this->runCommand($command), $command.' carries no signal worth exporting');
    }

    public function test_ordinary_commands_still_open_a_command_root(): void
    {
        self::assertSame(['reports:generate'], $this->runCommand('reports:generate'));
    }
}
