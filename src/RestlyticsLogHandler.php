<?php

declare(strict_types=1);

namespace Restlytics\Laravel;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

/** Monolog handler used by Laravel's default channel; capture remains opt-in. */
final class RestlyticsLogHandler extends AbstractProcessingHandler
{
    public function __construct(private readonly LogBuffer $buffer)
    {
        parent::__construct(Level::Debug, true);
    }

    protected function write(LogRecord $record): void
    {
        $this->buffer->capture($record->level->value, $record->message, $record->context);
    }
}
