<?php

declare(strict_types=1);

namespace Hilos\Log;

/**
 * Immutable summary of one log key across the whole log store (HIL-383).
 *
 * A "log key" is a file basename (for example `agent-hilos_logs.log`), stable across rotation
 * batches — the log-stream identity a by-key drill-down needs. Projected by
 * {@see LogStoreSnapshot::keys()} from a single walk: the two worker prefixes (worker and
 * worker-monopolistic) are folded into one {@see self::CLASS_WORKER} class, so this view answers
 * "which streams exist and how big are they" without the monopolistic split (see
 * {@see LogWorkerSummary} for that distinction). Internal read value-object, not a signal payload.
 */
final class LogKeySummary
{
    /** Key whose basename carries the `agent-` prefix. */
    public const string CLASS_AGENT = 'agent';

    /** Key whose basename carries the `worker-` or `worker-monopolistic-` prefix. */
    public const string CLASS_WORKER = 'worker';

    /**
     * @param string $key File basename, stable across batches
     * @param string $class Stream class, {@see self::CLASS_AGENT} or {@see self::CLASS_WORKER}
     * @param bool $live Whether the key is present among the live (non-archived) log files
     * @param list<int> $batchTimestamps Ascending Unix timestamps of the batches the key occurs in
     * @param int $totalBytes Summed size in bytes across the live file and every batch occurrence
     */
    public function __construct(
        public readonly string $key,
        public readonly string $class,
        public readonly bool $live,
        public readonly array $batchTimestamps,
        public readonly int $totalBytes,
    ) {
    }
}
