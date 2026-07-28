<?php

declare(strict_types=1);

namespace Hilos\Log;

/**
 * Mutable per-key accumulator used while projecting a {@see LogStoreSnapshot} (HIL-383).
 *
 * Collects a single log key's occurrences across the live directory and the archive batches into
 * the fields the read models expose ({@see LogKeySummary}, {@see LogWorkerSummary}). It exists only
 * to keep the projection typed instead of merging into an ad-hoc fixed-key array; it is never
 * returned past the snapshot's own projection methods.
 */
final class LogStreamAggregate
{
    /** Whether the key is present among the live (non-archived) log files. */
    public bool $live = false;

    /** @var list<int> Unix timestamps of the batches the key occurs in, in walk order (ascending) */
    public array $batchTimestamps = [];

    /** Summed size in bytes across the live file and every batch occurrence. */
    public int $totalBytes = 0;

    /**
     * Record the live-directory occurrence of this key.
     *
     * @param int $size Live file size in bytes
     */
    public function addLiveFile(int $size): void
    {
        $this->live = true;
        $this->totalBytes += $size;
    }

    /**
     * Record one archive batch occurrence of this key.
     *
     * @param int $timestamp Batch Unix timestamp (callers pass batches ascending)
     * @param int $size Archived file size in bytes
     */
    public function addBatchFile(int $timestamp, int $size): void
    {
        $this->batchTimestamps[] = $timestamp;
        $this->totalBytes += $size;
    }
}
