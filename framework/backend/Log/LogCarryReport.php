<?php

declare(strict_types=1);

namespace Hilos\Log;

/**
 * What one {@see LogBatchCarrier::carry()} did, for the caller to put in its own journal (HIL-870).
 *
 * Shaped after {@see LogRotationReport} and for the same reason: the mechanics say what happened
 * and the agent that asked writes the lines, so the same carrier can be driven from a test without
 * a Logger anywhere near it.
 *
 * A failure is a STRING rather than a flag because the operator's question is never "did it fail"
 * — a far volume that is full and one that is unreachable call for different hands — and the
 * carrier is the only place that knows which of the four steps refused.
 */
final class LogCarryReport
{
    /**
     * @param string $batchName Name of the batch directory this carry was about
     * @param bool $renamedWhole Whether the batch moved as one rename, with nothing copied
     * @param int $movedFileCount Files copied into the archive and removed from staging
     * @param ?string $failure Why the batch is still in staging, or null when it arrived
     */
    public function __construct(
        public readonly string $batchName,
        public readonly bool $renamedWhole,
        public readonly int $movedFileCount,
        public readonly ?string $failure,
    ) {
    }

    /**
     * Report of a batch that moved as one rename, the ordinary installation where the archive
     * sits on the device of the live logs.
     *
     * @param string $batchName Name of the batch directory that moved
     * @return self Report of a whole-directory rename, with nothing copied
     */
    public static function renamedWhole(string $batchName): self
    {
        return new self($batchName, true, 0, null);
    }

    /**
     * Report of a batch that reached the archive file by file, across a device boundary.
     *
     * @param string $batchName Name of the batch directory that arrived
     * @param int $movedFileCount Files copied into the archive and removed from staging
     * @return self Report of a completed copy
     */
    public static function copied(string $batchName, int $movedFileCount): self
    {
        return new self($batchName, false, $movedFileCount, null);
    }

    /**
     * Report of a carry that could not finish, naming what stopped it.
     *
     * @param string $batchName Name of the batch directory still in staging
     * @param int $movedFileCount Files that did reach the archive before the refusal
     * @param string $failure One line saying what refused
     * @return self Report of a batch left where it was
     */
    public static function failed(string $batchName, int $movedFileCount, string $failure): self
    {
        return new self($batchName, false, $movedFileCount, $failure);
    }
}
