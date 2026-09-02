<?php

declare(strict_types=1);

namespace Hilos\Log;

/**
 * What one {@see LogArchivePruner::prune()} did, for the caller to put in its own journal (HIL-382).
 *
 * The shape {@see LogRotationReport} has, and for the same reason: the pruner is a plain class with
 * no journal of its own, so it accounts for the pass and {@see LogStoreAgent} writes the lines under
 * its own name. Unlike the backup pruner, which removes a file it could recreate, this one removes
 * the only copy of a log — so every outcome of a pass is named here, including the batches it
 * decided to leave alone.
 *
 * Three of the four fields are refusals rather than failures, and they are apart because an operator
 * does something different about each: a path that would not go is worth retrying, a batch holding a
 * file that is not a log is waiting for a person to look at it, and a batch whose marker cannot be
 * read has quietly gone back to being un-carried-off.
 */
final class LogPruneReport
{
    /**
     * @param array<int, int> $removedBatchTimestamps Timestamp of every batch whose directory is gone,
     *     mapped to the stamp its takeout marker carried
     * @param list<string> $failedPaths Files and directories the pass could not remove, in the order it tried them
     * @param list<string> $keptDirNames Batch directory names left whole because they hold files that are not logs
     * @param list<string> $unreadableMarkerDirNames Batch directory names whose takeout marker could not be read
     */
    public function __construct(
        public readonly array $removedBatchTimestamps,
        public readonly array $failedPaths,
        public readonly array $keptDirNames,
        public readonly array $unreadableMarkerDirNames,
    ) {
    }
}
