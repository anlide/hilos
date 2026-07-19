<?php

declare(strict_types=1);

namespace Hilos\Backup;

use DateTimeImmutable;
use DateTimeZone;
use Hilos\Runtime\State\Item\BackupHistory;
use Throwable;

/**
 * BackupPruner - the stateless rotation planner and stored-file deleter.
 *
 * Rotation is a pure recompute: every pass {@see selectForDeletion()} derives the full
 * keep-set from scratch (no promotion flags on the rows) and returns everything else for
 * deletion, so the policy is self-healing and never drifts. The keep-set is the union of:
 * - each scope's own grid - the newest backup in every populated day / ISO-week /
 *   calendar-month / calendar-year bucket, capped at the policy's per-tier depth; the three
 *   scopes keep independent grids that never evict each other;
 * - the full restore timeline - across all scopes, each bucket keeps one representative
 *   preferring full over schema-seed over schema-only (newest within the best available),
 *   so the primary timeline has no holes even where no true full exists yet;
 * - pinned rows ({@see BackupHistory::$keep}), which sit outside the grids and are never removed;
 * - the newest {@see BackupRetentionPolicy::$errorCount} error records, which never enter the
 *   restore grids because they carry no restore value.
 *
 * Bucket membership is derived on the fly from each row's timestamp in the given timezone; no
 * bucket is ever stored on a row or in a sidecar. A row whose timestamp cannot be parsed is
 * kept, so a malformed index row is never silently deleted.
 *
 * {@see deleteStored()} is the shared physical-delete path (archive + sidecar) reused by manual
 * delete (HIL-333); the agent owns removing the matching runtime index row.
 */
final class BackupPruner
{
    /** Bucket format for the daily tier (calendar day in the target timezone). */
    private const string FORMAT_DAY = 'Y-m-d';

    /** Bucket format for the weekly tier (ISO-8601 year and week). */
    private const string FORMAT_WEEK = 'o-W';

    /** Bucket format for the monthly tier (calendar month). */
    private const string FORMAT_MONTH = 'Y-m';

    /** Bucket format for the yearly tier (calendar year). */
    private const string FORMAT_YEAR = 'Y';

    /**
     * Plans one rotation pass: returns the index rows whose stored files should be deleted.
     *
     * Pure - no I/O and no clock read; the caller supplies the timezone used for bucketing.
     *
     * @param list<BackupHistory> $rows Current backup index rows
     * @param BackupRetentionPolicy $policy Retention depths and error count
     * @param DateTimeZone $timezone Timezone the day/week/month/year buckets are derived in
     * @return list<BackupHistory> Rows to prune (never any pinned, error-retained, or grid representative)
     */
    public function selectForDeletion(array $rows, BackupRetentionPolicy $policy, DateTimeZone $timezone): array
    {
        $times = $this->indexTimes($rows, $timezone);

        /** @var array<string, true> $keep */
        $keep = [];
        /** @var list<BackupHistory> $success */
        $success = [];
        /** @var list<BackupHistory> $errors */
        $errors = [];

        foreach ($rows as $row) {
            $id = $row->getId();
            // Pinned rows and rows with an unparsable timestamp are kept unconditionally:
            // rotation only removes backups it can confidently place in a bucket.
            if ($row->keep || !isset($times[$id])) {
                $keep[$id] = true;
                continue;
            }

            $status = BackupStatus::fromString($row->status);
            if ($status === BackupStatus::SUCCESS) {
                $success[] = $row;
            } elseif ($status === BackupStatus::ERROR) {
                $errors[] = $row;
            } else {
                $keep[$id] = true;
            }
        }

        foreach (BackupScope::cases() as $scope) {
            $scopeRows = array_values(
                array_filter($success, static fn(BackupHistory $row): bool => $row->scope === $scope->value),
            );
            $this->addGridKeep($scopeRows, $times, $policy, $keep);
        }
        $this->addTimelineKeep($success, $times, $policy, $keep);
        $this->keepNewestErrors($errors, $times, $policy->errorCount, $keep);

        $doomed = [];
        foreach ($rows as $row) {
            if (!isset($keep[$row->getId()])) {
                $doomed[] = $row;
            }
        }

        return $doomed;
    }

    /**
     * Deletes the stored archive and sidecar for one backup (best effort).
     *
     * The shared physical-delete path: rotation and manual delete (HIL-333) both route here.
     * A missing archive (e.g. an error record) or already-removed file is not an error.
     *
     * @param BackupHistory $row Index row identifying the backup to remove
     * @param string $root Backup storage root; a blank root is a no-op
     */
    public function deleteStored(BackupHistory $row, string $root): void
    {
        $scope = BackupScope::fromString($row->scope);
        if ($scope === null || $root === '') {
            return;
        }

        $base = BackupCreator::archiveBaseName($row->getId(), $row->env, $scope);
        $scopeDir = $root . '/' . $scope->value;
        @unlink($scopeDir . '/' . $base . BackupHistoryScanner::ARCHIVE_EXTENSION);
        @unlink($scopeDir . '/' . $base . BackupHistoryScanner::SIDECAR_EXTENSION);
    }

    /**
     * Adds one scope grid's representatives (newest per bucket, per tier) to the keep-set.
     *
     * @param list<BackupHistory> $scopeRows Success rows of a single scope
     * @param array<string, DateTimeImmutable> $times Parsed timestamps keyed by backup id
     * @param BackupRetentionPolicy $policy Retention depths
     * @param array<string, true> $keep Keep-set to extend, keyed by backup id
     */
    private function addGridKeep(array $scopeRows, array $times, BackupRetentionPolicy $policy, array &$keep): void
    {
        $this->keepNewestBuckets($scopeRows, $times, self::FORMAT_DAY, $policy->daily, $keep);
        $this->keepNewestBuckets($scopeRows, $times, self::FORMAT_WEEK, $policy->weekly, $keep);
        $this->keepNewestBuckets($scopeRows, $times, self::FORMAT_MONTH, $policy->monthly, $keep);
        $this->keepNewestBuckets($scopeRows, $times, self::FORMAT_YEAR, $policy->yearly, $keep);
    }

    /**
     * Adds the full-timeline representatives (best available per bucket, per tier) to the keep-set.
     *
     * @param list<BackupHistory> $success All success rows across every scope
     * @param array<string, DateTimeImmutable> $times Parsed timestamps keyed by backup id
     * @param BackupRetentionPolicy $policy Retention depths
     * @param array<string, true> $keep Keep-set to extend, keyed by backup id
     */
    private function addTimelineKeep(array $success, array $times, BackupRetentionPolicy $policy, array &$keep): void
    {
        $this->keepBestBuckets($success, $times, self::FORMAT_DAY, $policy->daily, $keep);
        $this->keepBestBuckets($success, $times, self::FORMAT_WEEK, $policy->weekly, $keep);
        $this->keepBestBuckets($success, $times, self::FORMAT_MONTH, $policy->monthly, $keep);
        $this->keepBestBuckets($success, $times, self::FORMAT_YEAR, $policy->yearly, $keep);
    }

    /**
     * Keeps the newest row in each of the newest `$depth` buckets for one tier.
     *
     * @param list<BackupHistory> $rows Candidate rows (single scope)
     * @param array<string, DateTimeImmutable> $times Parsed timestamps keyed by backup id
     * @param string $format Date format naming the tier's bucket
     * @param int $depth Buckets to keep; a non-positive depth disables the tier
     * @param array<string, true> $keep Keep-set to extend, keyed by backup id
     */
    private function keepNewestBuckets(array $rows, array $times, string $format, int $depth, array &$keep): void
    {
        if ($depth <= 0) {
            return;
        }

        /** @var array<string, BackupHistory> $buckets */
        $buckets = [];
        foreach ($rows as $row) {
            $bucket = $times[$row->getId()]->format($format);
            if (!isset($buckets[$bucket]) || $times[$row->getId()] > $times[$buckets[$bucket]->getId()]) {
                $buckets[$bucket] = $row;
            }
        }

        $this->keepTopBuckets($buckets, $depth, $keep);
    }

    /**
     * Keeps the best-available representative in each of the newest `$depth` buckets for one tier.
     *
     * @param list<BackupHistory> $rows Candidate rows (all scopes)
     * @param array<string, DateTimeImmutable> $times Parsed timestamps keyed by backup id
     * @param string $format Date format naming the tier's bucket
     * @param int $depth Buckets to keep; a non-positive depth disables the tier
     * @param array<string, true> $keep Keep-set to extend, keyed by backup id
     */
    private function keepBestBuckets(array $rows, array $times, string $format, int $depth, array &$keep): void
    {
        if ($depth <= 0) {
            return;
        }

        /** @var array<string, BackupHistory> $buckets */
        $buckets = [];
        foreach ($rows as $row) {
            $bucket = $times[$row->getId()]->format($format);
            if (!isset($buckets[$bucket]) || $this->outranks($row, $buckets[$bucket], $times)) {
                $buckets[$bucket] = $row;
            }
        }

        $this->keepTopBuckets($buckets, $depth, $keep);
    }

    /**
     * Adds the representatives of the newest `$depth` buckets to the keep-set.
     *
     * @param array<string, BackupHistory> $buckets Representative row keyed by bucket
     * @param int $depth Buckets to keep
     * @param array<string, true> $keep Keep-set to extend, keyed by backup id
     */
    private function keepTopBuckets(array $buckets, int $depth, array &$keep): void
    {
        krsort($buckets, SORT_STRING);

        $kept = 0;
        foreach ($buckets as $row) {
            if ($kept >= $depth) {
                break;
            }
            $keep[$row->getId()] = true;
            $kept++;
        }
    }

    /**
     * Keeps the newest `$count` error records.
     *
     * @param list<BackupHistory> $errors Error rows with parsed timestamps
     * @param array<string, DateTimeImmutable> $times Parsed timestamps keyed by backup id
     * @param int $count Newest error rows to keep; a non-positive count keeps none
     * @param array<string, true> $keep Keep-set to extend, keyed by backup id
     */
    private function keepNewestErrors(array $errors, array $times, int $count, array &$keep): void
    {
        if ($count <= 0 || $errors === []) {
            return;
        }

        usort(
            $errors,
            static fn(BackupHistory $a, BackupHistory $b): int => $times[$b->getId()] <=> $times[$a->getId()],
        );

        foreach (array_slice($errors, 0, $count) as $row) {
            $keep[$row->getId()] = true;
        }
    }

    /**
     * Reports whether a candidate should replace the current bucket representative.
     *
     * A stronger scope wins outright; within the same scope the newer row wins.
     *
     * @param BackupHistory $candidate Row being considered
     * @param BackupHistory $current Current representative
     * @param array<string, DateTimeImmutable> $times Parsed timestamps keyed by backup id
     * @return bool True when the candidate outranks the current representative
     */
    private function outranks(BackupHistory $candidate, BackupHistory $current, array $times): bool
    {
        $candidateRank = self::scopeRank($candidate->scope);
        $currentRank = self::scopeRank($current->scope);
        if ($candidateRank !== $currentRank) {
            return $candidateRank < $currentRank;
        }

        return $times[$candidate->getId()] > $times[$current->getId()];
    }

    /**
     * Parses every row's creation timestamp into the target timezone, skipping malformed ones.
     *
     * @param list<BackupHistory> $rows Index rows
     * @param DateTimeZone $timezone Timezone the timestamps are normalized to
     * @return array<string, DateTimeImmutable> Parsed timestamps keyed by backup id
     */
    private function indexTimes(array $rows, DateTimeZone $timezone): array
    {
        $times = [];
        foreach ($rows as $row) {
            $parsed = $this->parseTime($row->createdAt, $timezone);
            if ($parsed !== null) {
                $times[$row->getId()] = $parsed;
            }
        }

        return $times;
    }

    /**
     * Parses one ISO-8601 timestamp into the target timezone, or null when it cannot be parsed.
     *
     * @param string $createdAt Stored ISO-8601 timestamp
     * @param DateTimeZone $timezone Timezone the result is expressed in
     * @return ?DateTimeImmutable Parsed timestamp, or null on empty/malformed input
     */
    private function parseTime(string $createdAt, DateTimeZone $timezone): ?DateTimeImmutable
    {
        if ($createdAt === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($createdAt))->setTimezone($timezone);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Ranks a scope for full-timeline backfill: full is best, then schema-seed, then schema-only.
     *
     * @param string $scope Stored scope value
     * @return int Rank where a lower value is a stronger restore candidate
     */
    private static function scopeRank(string $scope): int
    {
        return match (BackupScope::fromString($scope)) {
            BackupScope::FULL => 0,
            BackupScope::SCHEMA_SEED => 1,
            BackupScope::SCHEMA_ONLY => 2,
            null => 3,
        };
    }
}
