<?php

declare(strict_types=1);

namespace Restlytics\Laravel\Transport;

/** Payload-free process-local delivery counters for health checks and shutdown logs. */
final readonly class TransportDiagnostics
{
    public function __construct(
        public int $acceptedBatches,
        public int $deliveredBatches,
        public int $droppedBatches,
        public int $failedBatches,
        public int $queuedBatches,
        public int $inFlightBatches,
        public int $queueCapacity,
        public bool $closed,
    ) {}
}
