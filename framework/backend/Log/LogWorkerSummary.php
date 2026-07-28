<?php

declare(strict_types=1);

namespace Hilos\Log;

/**
 * Immutable summary of one worker log stream across the whole log store (HIL-383).
 *
 * Like {@see LogKeySummary} but scoped to the worker streams and carrying the distinction the
 * folded key view drops: whether the stream is a monopolistic worker (`worker-monopolistic-`
 * prefix) or a regular one (`worker-` prefix). Projected by {@see LogStoreSnapshot::workers()}
 * from a single walk. Internal read value-object, not a signal payload.
 */
final class LogWorkerSummary
{
    /**
     * @param string $key File basename, stable across batches
     * @param bool $monopolistic Whether the stream is a monopolistic worker (`worker-monopolistic-` prefix)
     * @param bool $live Whether the key is present among the live (non-archived) log files
     * @param list<int> $batchTimestamps Ascending Unix timestamps of the batches the key occurs in
     * @param int $totalBytes Summed size in bytes across the live file and every batch occurrence
     */
    public function __construct(
        public readonly string $key,
        public readonly bool $monopolistic,
        public readonly bool $live,
        public readonly array $batchTimestamps,
        public readonly int $totalBytes,
    ) {
    }
}
