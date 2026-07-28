<?php

declare(strict_types=1);

namespace Hilos\Log;

/**
 * Immutable result of one {@see LogStoreReader::read()} walk of the log store (HIL-383).
 *
 * Carries the classified live + archive index produced by a single directory walk and projects it
 * into typed read models on demand. Unavailability (missing env, unreadable archive) is part of the
 * result rather than an exception: {@see $available} is false and every projection returns an empty
 * list, mirroring the overview page's former `setUnavailableState`.
 *
 * The index is keyed by the {@see LogStoreReader} class constants; the projections fold the two
 * worker prefixes together for {@see keys()} but keep them apart for {@see workers()}.
 */
final class LogStoreSnapshot
{
    /**
     * @param bool $available Whether the log store could be read
     * @param array<int, array{agent: array<string, int>, worker: array<string, int>, workerMonopolistic: array<string, int>}> $archiveBatches
     *        Batch Unix timestamp → classified basename → size in bytes
     * @param array{agent: array<string, int>, worker: array<string, int>, workerMonopolistic: array<string, int>} $liveFiles
     *        Live (non-archived) classified basename → size in bytes
     */
    public function __construct(
        public readonly bool $available,
        private readonly array $archiveBatches,
        private readonly array $liveFiles,
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
            LogStoreReader::CLASS_AGENT => [],
            LogStoreReader::CLASS_WORKER => [],
            LogStoreReader::CLASS_WORKER_MONOPOLISTIC => [],
        ]);
    }

    /**
     * Per rotation batch summary, oldest first.
     *
     * @return list<LogBatchSummary> One entry per archive batch, ascending by timestamp
     */
    public function batches(): array
    {
        $timestamps = $this->sortedBatchTimestamps();

        $summaries = [];
        foreach ($timestamps as $timestamp) {
            $agent = $this->archiveBatches[$timestamp][LogStoreReader::CLASS_AGENT];
            $worker = $this->archiveBatches[$timestamp][LogStoreReader::CLASS_WORKER];
            $workerMonopolistic = $this->archiveBatches[$timestamp][LogStoreReader::CLASS_WORKER_MONOPOLISTIC];
            $summaries[] = new LogBatchSummary(
                timestamp: $timestamp,
                agentFileCount: count($agent),
                agentBytes: array_sum($agent),
                workerFileCount: count($worker),
                workerBytes: array_sum($worker),
                workerMonopolisticFileCount: count($workerMonopolistic),
                workerMonopolisticBytes: array_sum($workerMonopolistic),
            );
        }

        return $summaries;
    }

    /**
     * Per log key summary across live and archive, sorted by key.
     *
     * The two worker prefixes are folded into {@see LogKeySummary::CLASS_WORKER}; use {@see workers()}
     * when the monopolistic distinction is needed.
     *
     * @return list<LogKeySummary> One entry per distinct basename, sorted ascending by key
     */
    public function keys(): array
    {
        $summaries = [];
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
     * @param list<string> $classes {@see LogStoreReader} class keys to merge (worker + monopolistic share a key space only across classes, never within a basename)
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
                foreach ($this->archiveBatches[$timestamp][$class] as $name => $size) {
                    ($streams[$name] ??= new LogStreamAggregate())->addBatchFile($timestamp, $size);
                }
            }
        }

        return $streams;
    }

    /**
     * Archive batch timestamps in ascending order.
     *
     * @return list<int> Sorted Unix timestamps
     */
    private function sortedBatchTimestamps(): array
    {
        $timestamps = array_keys($this->archiveBatches);
        sort($timestamps);

        return $timestamps;
    }
}
