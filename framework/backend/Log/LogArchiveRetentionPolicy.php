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
 * The policy only *recommends*: a batch it names a candidate is not deleted here. A candidate
 * becomes an acknowledged instruction when an operator confirms carrying it off and the node
 * writes that down ({@see LogBatchTakeoutMarker}, HIL-483), and the pruner (HIL-382) will delete
 * only what was acknowledged that way.
 *
 * Candidacy is the union-keep of the two criteria: a batch is a candidate only when it is BOTH
 * outside the newest {@see $keepBatches} AND older than {@see $maxAgeSeconds}. Either threshold
 * at 0 disables that criterion, leaving the other to decide; both at 0 means nothing is ever a
 * candidate.
 *
 * A value the environment cannot answer makes the whole policy inert — both thresholds 0, the
 * reasons named in {@see $unreadable} — because here a disabled criterion widens eviction instead
 * of narrowing it, and a typo must never hand the pruner more batches than the operator asked for.
 */
final class LogArchiveRetentionPolicy
{
    /**
     * @param int $keepBatches Newest batches always kept; 0 disables the count criterion
     * @param int $maxAgeSeconds Age in seconds beyond which a batch is eligible; 0 disables the age criterion
     * @param array<string, string> $unreadable Reason by environment variable name for every value
     *                                          that could not be read; empty when the environment
     *                                          answered
     */
    public function __construct(
        public readonly int $keepBatches,
        public readonly int $maxAgeSeconds,
        public readonly array $unreadable = [],
    ) {
    }

    /**
     * Builds the policy from the environment, clamping negatives to 0 (disabled).
     *
     * Either value being unreadable yields the inert policy — no candidates at all — rather than a
     * partly configured one, because a 0 threshold here removes a constraint instead of adding one.
     * No access to the environment at all yields the same pair of zeroes silently, which is the
     * documented contract of this class.
     *
     * @return self Policy carrying the configured keep-count and max-age thresholds
     */
    public static function fromEnv(): self
    {
        $unreadable = [];
        $keepBatches = self::envInt(EnvConstants::LOG_ARCHIVE_RETENTION_KEEP_BATCHES, $unreadable);
        $maxAge = self::envInt(EnvConstants::LOG_ARCHIVE_RETENTION_MAX_AGE_SECONDS, $unreadable);

        return $unreadable === [] ? new self($keepBatches, $maxAge) : new self(0, 0, $unreadable);
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

    /**
     * Reads one threshold, recording why when the environment cannot answer.
     *
     * @param EnvConstants $key Environment variable backing this threshold
     * @param array<string, string> $unreadable Accumulator the failure reason is added to, keyed by variable name
     * @return int Configured threshold clamped to 0 or more; 0 when the value could not be read
     */
    private static function envInt(EnvConstants $key, array &$unreadable): int
    {
        try {
            return max(0, Hilos::$env?->int($key) ?? 0);
        } catch (EnvException $exception) {
            $unreadable[$key->name] = $exception->getMessage();

            return 0;
        }
    }
}
