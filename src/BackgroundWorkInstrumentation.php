<?php

declare(strict_types=1);

namespace Restlytics\Laravel;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Restlytics\Laravel\Support\Ids;

/**
 * Laravel adapters for SPEC §11 background work (queue jobs, Artisan, schedule).
 * Soft-registers: missing illuminate/queue or console events → no-op.
 */
final class BackgroundWorkInstrumentation
{
    /** Artisan commands that wrap other work units — never open a command root. */
    private const SUPPRESSED_COMMANDS = [
        'queue:work',
        'queue:listen',
        'queue:retry',
        'horizon',
        'horizon:work',
        'horizon:supervisor',
        'schedule:run',
        'schedule:work',
        'schedule:finish',
        'inspire',
        'about',
        'list',
        'help',
    ];

    public function __construct(
        private readonly Tracer $tracer,
        private readonly Work $work,
    ) {}

    public function register(): void
    {
        $this->registerQueue();
        $this->registerCommands();
        $this->registerSchedule();
    }

    private function registerQueue(): void
    {
        if (! class_exists(Queue::class)) {
            return;
        }

        try {
            Queue::createPayloadUsing(function (string $connection, ?string $queue, array $payload): array {
                try {
                    if ($this->tracer->isSampled() && $this->tracer->hasActiveRoot()) {
                        $this->work->recordEnqueue(
                            $payload,
                            destination: $queue ?: ($connection ?: 'default'),
                            system: 'laravel',
                        );
                    }
                } catch (\Throwable) {
                    // never break enqueue
                }

                return $payload;
            });
        } catch (\Throwable) {
            // createPayloadUsing unavailable
        }

        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            try {
                if ($this->tracer->hasActiveRoot()) {
                    // sync / afterResponse — stay under the HTTP root
                    return;
                }

                $job = $event->job;
                $name = method_exists($job, 'resolveName') ? (string) $job->resolveName() : $job->getName();
                $payload = [];
                try {
                    $raw = $job->getRawBody();
                    $decoded = json_decode($raw, true);
                    $payload = is_array($decoded) ? $decoded : [];
                } catch (\Throwable) {
                    $payload = [];
                }

                $carrier = Ids::extractCarrier($payload);
                $attempt = 1;
                if (method_exists($job, 'attempts')) {
                    $attempt = max(1, (int) $job->attempts());
                }
                $maxAttempts = null;
                if (method_exists($job, 'maxTries') && $job->maxTries() !== null) {
                    $maxAttempts = (int) $job->maxTries();
                }

                $this->work->startJob($name, $carrier, array_filter([
                    'system' => 'laravel',
                    'destination' => method_exists($job, 'getQueue') ? (string) ($job->getQueue() ?: 'default') : 'default',
                    'messageId' => method_exists($job, 'getJobId') ? (string) ($job->getJobId() ?? '') : '',
                    'attempt' => $attempt,
                    'maxAttempts' => $maxAttempts,
                ], static fn ($v) => $v !== null && $v !== ''));
            } catch (\Throwable) {
                // never break the worker
            }
        });

        Event::listen(JobProcessed::class, function (): void {
            try {
                $this->work->finishJob(false);
            } catch (\Throwable) {
            }
        });

        Event::listen(JobFailed::class, function (): void {
            try {
                $this->work->finishJob(true);
            } catch (\Throwable) {
            }
        });
    }

    private function registerCommands(): void
    {
        if (! class_exists(CommandStarting::class)) {
            return;
        }

        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            try {
                $name = (string) $event->command;
                if ($this->isSuppressedCommand($name)) {
                    return;
                }
                $this->work->startCommand($name);
            } catch (\Throwable) {
            }
        });

        Event::listen(CommandFinished::class, function (CommandFinished $event): void {
            try {
                $name = (string) $event->command;
                if ($this->isSuppressedCommand($name)) {
                    return;
                }
                $this->work->finishCommand((int) $event->exitCode);
            } catch (\Throwable) {
            }
        });
    }

    private function registerSchedule(): void
    {
        // String names keep this soft when illuminate/console schedule events differ.
        Event::listen('Illuminate\\Console\\Events\\ScheduledTaskStarting', function (object $event): void {
            try {
                $task = $event->task ?? null;
                if ($task === null) {
                    return;
                }
                $name = method_exists($task, 'description') && $task->description
                    ? (string) $task->description
                    : (method_exists($task, 'command') ? (string) $task->command : 'scheduled-task');
                $cron = method_exists($task, 'expression') ? (string) $task->expression : null;
                $this->work->startSchedule($name !== '' ? $name : 'scheduled-task', $cron);
            } catch (\Throwable) {
            }
        });

        Event::listen('Illuminate\\Console\\Events\\ScheduledTaskFinished', function (): void {
            try {
                $this->work->finishSchedule(false);
            } catch (\Throwable) {
            }
        });

        Event::listen('Illuminate\\Console\\Events\\ScheduledTaskFailed', function (): void {
            try {
                $this->work->finishSchedule(true);
            } catch (\Throwable) {
            }
        });
    }

    private function isSuppressedCommand(string $name): bool
    {
        $name = strtolower(trim($name));
        foreach (self::SUPPRESSED_COMMANDS as $suppressed) {
            if ($name === $suppressed || str_starts_with($name, $suppressed.':') || str_starts_with($name, $suppressed.' ')) {
                return true;
            }
        }

        return false;
    }
}
