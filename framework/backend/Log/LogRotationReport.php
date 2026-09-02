<?php

declare(strict_types=1);

namespace Hilos\Log;

/**
 * What one {@see LogRotator::rotate()} did, for the caller to put in its own journal (HIL-480).
 *
 * The rotator used to call the Logger itself, and a rotator running inside a worker wrote the
 * rotation line into that worker's log — not into the journal of whoever asked for it. It now
 * reports instead, and the line is written by the caller under its own name: the watchdog on the
 * start path, {@see LogStoreAgent} on the runtime one.
 */
final class LogRotationReport
{
    /**
     * @param int $movedCount Live log files renamed into the batch
     * @param ?string $batchDirName Name of the batch directory, or null when none was created
     * @param list<string> $failedFiles Paths that could not be moved and stayed live
     */
    public function __construct(
        public readonly int $movedCount,
        public readonly ?string $batchDirName,
        public readonly array $failedFiles,
    ) {
    }

    /**
     * Report of a rotation that found nothing to move, and so created no batch directory.
     *
     * @return self Report with no moves, no batch and no failures
     */
    public static function nothingToRotate(): self
    {
        return new self(0, null, []);
    }
}
