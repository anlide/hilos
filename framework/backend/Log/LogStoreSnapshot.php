<?php

declare(strict_types=1);

namespace Hilos\Log;

/**
 * Immutable result of one {@see LogStoreReader::read()} walk of the log store (HIL-383).
 *
 * Carries the classified index of the log root and of both batch subtrees — staging and archive —
 * produced by a single directory walk, and projects it
 * into typed read models on demand. Unavailability (missing env, unreadable subtree) is part of the
 * result rather than an exception: {@see $available} is false and every projection returns an empty
 * list, mirroring the overview page's former `setUnavailableState`.
 *
 * The index is keyed by the {@see LogStoreReader} class constants; the projections fold the two
 * worker prefixes together for {@see keys()} but keep them apart for {@see workers()}, and the
 * daemon streams reach {@see keys()} and {@see batches()} but never {@see workers()} — the daemon
 * is not a worker.
 */
final class LogStoreSnapshot
{
    /**
     * @param bool $available Whether the log store could be read
     * @param array<int, array{daemon: array<string, int>, agent: array<string, int>, worker: array<string, int>,
     *     workerMonopolistic: array<string, int>}> $batchFiles Batch Unix timestamp → classified basename → size in bytes,
     *     over the archive and staging together
     * @param array{daemon: array<string, int>, agent: array<string, int>, worker: array<string, int>,
     *     workerMonopolistic: array<string, int>} $liveFiles Live (non-archived) classified basename → size in bytes
     * @param array<int, ?int> $batchTakenAt Batch Unix timestamp → the stamp its takeout marker carries, null when it carries none
     * @param list<int> $carryingTimestamps Batch Unix timestamps still in the staging directory, on their way to the archive
     */
    public function __construct(
        public readonly bool $available,
        private readonly array $batchFiles,
        private readonly array $liveFiles,
        private readonly array $batchTakenAt = [],
        private readonly array $carryingTimestamps = [],
    ) {
    }

    /**
     * Empty snapshot for an unreadable log store (missing env, scandir failure).
     *
     * @return self Snapshot with {@see $available} false and no files
     */
    public static function unavailable(): self
    {
        return new self(false, [], [
            LogStoreReader::CLASS_DAEMON => [],
            LogStoreReader::CLASS_AGENT => [],
            LogStoreReader::CLASS_WORKER => [],
            LogStoreReader::CLASS_WORKER_MONOPOLISTIC => [],
        ]);
    }

    /**
     * Copy of this snapshot carrying a fresh live-file index over the batches already walked.
     *
     * The cheap half of the split walk (HIL-753): {@see LogStoreReader::readLiveFiles()} resamples
     * the log root every few seconds, while the batches — which only rotation, the carry and the
     * cleanup change — stay as the last full {@see LogStoreReader::read()} saw them.
     *
     * @param array{daemon: array<string, int>, agent: array<string, int>, worker: array<string, int>,
     *     workerMonopolistic: array<string, int>} $liveFiles Live (non-archived) classified basename → size in bytes
     *
     * @return self Snapshot with this snapshot's batches and the given live files
     */
    public function withLiveFiles(array $liveFiles): self
    {
        return new self(
            $this->available,
            $this->batchFiles,
            $liveFiles,
            $this->batchTakenAt,
            $this->carryingTimestamps,
        );
    }

    /**
     * The live (non-archived) half of the walk, as classified.
     *
     * The batch half is projected; this one is handed back whole because a caller watching for
     * rotation compares live weights against live weights — a key's total, which counts its batches
     * too, would answer a different question (HIL-753).
     *
     * @return array{daemon: array<string, int>, agent: array<string, int>, worker: array<string, int>,
     *     workerMonopolistic: array<string, int>} Classified basename → size in bytes
     */
    public function liveFiles(): array
    {
        return $this->liveFiles;
    }

    /**
     * Per rotation batch summary, oldest first.
     *
     * The takeout confirmation travels beside the figures rather than among them (HIL-483): it is
     * read off a marker file the walk does not weigh, so it changes what a batch IS without
     * changing a single count or byte of it. The carrying flag travels the same way and for the
     * same reason (HIL-870): a batch weighs what it weighs wherever it currently sits.
     *
     * @return list<LogBatchSummary> One entry per batch of the archive and of staging, ascending by timestamp
     */
    public function batches(): array
    {
        $timestamps = $this->sortedBatchTimestamps();

        $summaries = [];
        foreach ($timestamps as $timestamp) {
            $agent = $this->batchFiles[$timestamp][LogStoreReader::CLASS_AGENT];
            $worker = $this->batchFiles[$timestamp][LogStoreReader::CLASS_WORKER];
            $workerMonopolistic = $this->batchFiles[$timestamp][LogStoreReader::CLASS_WORKER_MONOPOLISTIC];
            $daemon = $this->batchFiles[$timestamp][LogStoreReader::CLASS_DAEMON];
            $summaries[] = new LogBatchSummary(
                timestamp: $timestamp,
                agentFileCount: count($agent),
                agentBytes: array_sum($agent),
                workerFileCount: count($worker),
                workerBytes: array_sum($worker),
                workerMonopolisticFileCount: count($workerMonopolistic),
                workerMonopolisticBytes: array_sum($workerMonopolistic),
                daemonFileCount: count($daemon),
                daemonBytes: array_sum($daemon),
                takenAt: $this->batchTakenAt[$timestamp] ?? null,
                carrying: in_array($timestamp, $this->carryingTimestamps, true),
            );
        }

        return $summaries;
    }

    /**
     * Per log key summary across the live files and every batch, sorted by key.
     *
     * The two worker prefixes are folded into {@see LogKeySummary::CLASS_WORKER}; use {@see workers()}
     * when the monopolistic distinction is needed. The daemon streams keep a class of their own.
     *
     * @return list<LogKeySummary> One entry per distinct basename, sorted ascending by key
     */
    public function keys(): array
    {
        $summaries = [];
        foreach ($this->collectStreams([LogStoreReader::CLASS_DAEMON]) as $key => $stream) {
            $summaries[] = new LogKeySummary(
                key: $key,
                class: LogKeySummary::CLASS_DAEMON,
                live: $stream->live,
                batchTimestamps: $stream->batchTimestamps,
                totalBytes: $stream->totalBytes,
            );
        }
        foreach ($this->collectStreams([LogStoreReader::CLASS_AGENT]) as $key => $stream) {
            $summaries[] = new LogKeySummary(
                key: $key,
                class: LogKeySummary::CLASS_AGENT,
                live: $stream->live,
                batchTimestamps: $stream->batchTimestamps,
                totalBytes: $stream->totalBytes,
            );
        }
        foreach ($this->collectStreams([LogStoreReader::CLASS_WORKER, LogStoreReader::CLASS_WORKER_MONOPOLISTIC]) as $key => $stream) {
            $summaries[] = new LogKeySummary(
                key: $key,
                class: LogKeySummary::CLASS_WORKER,
                live: $stream->live,
                batchTimestamps: $stream->batchTimestamps,
                totalBytes: $stream->totalBytes,
            );
        }
        usort($summaries, static fn (LogKeySummary $a, LogKeySummary $b): int => strcmp($a->key, $b->key));

        return $summaries;
    }

    /**
     * Per worker log stream summary, sorted by key, keeping the monopolistic distinction.
     *
     * @return list<LogWorkerSummary> One entry per distinct worker basename, sorted ascending by key
     */
    public function workers(): array
    {
        $summaries = [];
        foreach ($this->collectStreams([LogStoreReader::CLASS_WORKER]) as $key => $stream) {
            $summaries[] = new LogWorkerSummary(
                key: $key,
                monopolistic: false,
                live: $stream->live,
                batchTimestamps: $stream->batchTimestamps,
                totalBytes: $stream->totalBytes,
            );
        }
        foreach ($this->collectStreams([LogStoreReader::CLASS_WORKER_MONOPOLISTIC]) as $key => $stream) {
            $summaries[] = new LogWorkerSummary(
                key: $key,
                monopolistic: true,
                live: $stream->live,
                batchTimestamps: $stream->batchTimestamps,
                totalBytes: $stream->totalBytes,
            );
        }
        usort($summaries, static fn (LogWorkerSummary $a, LogWorkerSummary $b): int => strcmp($a->key, $b->key));

        return $summaries;
    }

    /**
     * Aggregate the given index classes into per-key streams (live flag, batch timestamps, total bytes).
     *
     * @param list<string> $classes {@see LogStoreReader} class keys to merge (worker + monopolistic
     *     share a key space only across classes, never within a basename)
     *
     * @return array<string, LogStreamAggregate> Basename → aggregated stream, discovery order
     */
    private function collectStreams(array $classes): array
    {
        $streams = [];
        foreach ($classes as $class) {
            foreach ($this->liveFiles[$class] as $name => $size) {
                ($streams[$name] ??= new LogStreamAggregate())->addLiveFile($size);
            }
        }
        foreach ($this->sortedBatchTimestamps() as $timestamp) {
            foreach ($classes as $class) {
                foreach ($this->batchFiles[$timestamp][$class] as $name => $size) {
                    ($streams[$name] ??= new LogStreamAggregate())->addBatchFile($timestamp, $size);
                }
            }
        }

        return $streams;
    }

    /**
     * Batch timestamps of the archive and of staging together, in ascending order.
     *
     * @return list<int> Sorted Unix timestamps
     */
    private function sortedBatchTimestamps(): array
    {
        $timestamps = array_keys($this->batchFiles);
        sort($timestamps);

        return $timestamps;
    }
}
