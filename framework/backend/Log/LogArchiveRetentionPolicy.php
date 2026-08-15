<?php

declare(strict_types=1);

namespace Hilos\Log;

use Hilos\Backup\BackupPruner;
use Hilos\Constants\EnvConstants;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;

/**
 * Immutable retention policy for archived log rotation batches (HIL-381).
 *
 * A pure candidate-selection policy over two independent criteria — how many of the newest
 * batches are always kept ({@see $keepBatches}) and how old a batch must be to be eligible
 * ({@see $maxAgeSeconds}). {@see selectEvictionCandidates()} is a pure predicate mirroring
 * {@see BackupPruner::selectForDeletion()}: no I/O and no clock read (the instant
 * ages are measured from is injected), recomputed from scratch each pass so it is self-healing
 * and carries no per-batch flags. Unlike the backup grid it needs no timeline reconstruction —
 * logs are a flat newest-first stream.
 *
 * The policy only *recommends*: a batch it names a candidate is not deleted here. The eviction
 * state machine (HIL-483) turns recommendations into acknowledged instructions, and the pruner
 * (HIL-382) deletes only what the operator acknowledged.
 *
 * Candidacy is the union-keep of the two criteria: a batch is a candidate only when it is BOTH
 * outside the newest {@see $keepBatches} AND older than {@see $maxAgeSeconds}. Either threshold
 * at 0 disables that criterion, leaving the other to decide; both at 0 means nothing is ever a
 * candidate.
 */
final class LogArchiveRetentionPolicy
{
    /**
     * @param int $keepBatches Newest batches always kept; 0 disables the count criterion
     * @param int $maxAgeSeconds Age in seconds beyond which a batch is eligible; 0 disables the age criterion
     */
    public function __construct(
        public readonly int $keepBatches,
        public readonly int $maxAgeSeconds,
    ) {
    }

    /**
     * Builds the policy from the environment, clamping negatives to 0 (disabled).
     *
     * @return self Policy carrying the configured keep-count and max-age thresholds
     * @throws EnvException When a retention key is missing, outside the catalog, or not an int
     */
    public static function fromEnv(): self
    {
        return new self(
            max(0, Hilos::$env?->int(EnvConstants::LOG_ARCHIVE_RETENTION_KEEP_BATCHES) ?? 0),
            max(0, Hilos::$env?->int(EnvConstants::LOG_ARCHIVE_RETENTION_MAX_AGE_SECONDS) ?? 0),
        );
    }

    /**
     * Pure predicate: picks the archived batches that are eviction candidates now.
     *
     * A batch qualifies only when it is BOTH outside the newest {@see $keepBatches} AND older than
     * {@see $maxAgeSeconds} relative to `$now`. A disabled criterion (threshold 0) stops constraining
     * candidacy; both disabled yields no candidates. The result preserves the input order.
     *
     * @param list<int> $batches Batch timestamps in Unix seconds (rotation-archive directory times)
     * @param int $now Instant every batch's age is measured against, in Unix seconds
     * @return list<int> The subset of `$batches` that are eviction candidates
     */
    public function selectEvictionCandidates(array $batches, int $now): array
    {
        if ($this->keepBatches <= 0 && $this->maxAgeSeconds <= 0) {
            return [];
        }

        $protected = $this->keepBatches > 0 ? $this->protectedByCount($batches) : [];

        $candidates = [];
        foreach ($batches as $index => $timestamp) {
            $outsideNewest = $this->keepBatches <= 0 || !isset($protected[$index]);
            $olderThanMax = $this->maxAgeSeconds <= 0 || ($now - $timestamp) > $this->maxAgeSeconds;
            if ($outsideNewest && $olderThanMax) {
                $candidates[] = $timestamp;
            }
        }

        return $candidates;
    }

    /**
     * Marks the original indexes of the newest {@see $keepBatches} batches (count-protected set).
     *
     * @param list<int> $batches Batch timestamps in Unix seconds
     * @return array<int, true> Original indexes of the batches exempt from eviction by count
     */
    private function protectedByCount(array $batches): array
    {
        $ordered = [];
        foreach ($batches as $index => $timestamp) {
            $ordered[] = [$timestamp, $index];
        }
        // Newest first; equal timestamps fall back to original order so the exemption is deterministic.
        usort($ordered, static fn(array $a, array $b): int => $b[0] <=> $a[0] ?: $a[1] <=> $b[1]);

        $protected = [];
        foreach (array_slice($ordered, 0, $this->keepBatches) as [$timestamp, $index]) {
            $protected[$index] = true;
        }

        return $protected;
    }
}
