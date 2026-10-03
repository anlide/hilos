<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics;

/** Retry of a node's failed file after a database error. */
final readonly class AnalyticsWriterNodePause
{
    /**
     * @param string $file File that must precede the node's later files
     * @param int $atMs Earliest moment to ask for it again
     * @param int $delayMs Current retry delay
     */
    public function __construct(
        public string $file,
        public int $atMs,
        public int $delayMs,
    ) {
    }
}
