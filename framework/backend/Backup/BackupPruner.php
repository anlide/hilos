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
 * deletion, so the policy is self-healing and never drifts.
 *
 * Thinning is a ladder of age bands, coarsening as backups get older
 * ({@see BackupRetentionPolicy}): nothing younger than the daily age is thinned at all, then
 * one per day, then per ISO week, per calendar month, and finally per calendar year. The
 * youngest band is the important one - a backup taken minutes ago is a deliberate act
 * (before a migration, before a risky change), and rotation must not eat one manual backup
 * because another was taken the same day.
 *
 * Within a band the keep-set is the union of:
 * - each scope's own grid - the newest backup in every populated bucket of that band; the
 *   three scopes keep independent grids that never evict each other;
 * - the full restore timeline - across all scopes, each bucket keeps one representative
 *   preferring full over schema-seed over schema-only (newest within the best available),
 *   so the primary timeline has no holes even where no true full exists yet;
 * - pinned rows ({@see BackupHistory::$keep}), which sit outside the grids and are never removed;
 * - the newest {@see BackupRetentionPolicy::$errorCount} error records, which never enter the
 *   restore grids because they carry no restore value.
 *
 * Band and bucket membership are derived on the fly from each row's timestamp in the given
 * timezone; neither is ever stored on a row or in a sidecar. A row whose timestamp cannot be
 * parsed is kept, so a malformed index row is never silently deleted.
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

    /** Band marker for backups too young to thin: every row in it is kept. */
    private const string BAND_KEEP_ALL = 'keep-all';

    /**
     * Plans one rotation pass: returns the index rows whose stored files should be deleted.
     *
     * Pure - no I/O and no clock read; the caller supplies both the timezone the buckets are
     * derived in and the instant the ages are measured from.
     *
     * @param list<BackupHistory> $rows Current backup index rows
     * @param BackupRetentionPolicy $policy Retention ages and error count
     * @param DateTimeZone $timezone Timezone the day/week/month/year buckets are derived in
     * @param DateTimeImmutable $now Instant every row's age is measured against
     * @return list<BackupHistory> Rows to prune (never any pinned, error-retained, or kept representative)
     */
    public function selectForDeletion(
        array $rows,
        BackupRetentionPolicy $policy,
        DateTimeZone $timezone,
        DateTimeImmutable $now,
    ): array {
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

        $bands = $this->groupByBand($success, $times, $policy, $now);
        foreach ($bands as $format => $bandRows) {
            if ($format === self::BAND_KEEP_ALL) {
                foreach ($bandRows as $row) {
                    $keep[$row->getId()] = true;
                }

                continue;
            }

            foreach (BackupScope::cases() as $scope) {
                $scopeRows = array_values(
                    array_filter($bandRows, static fn(BackupHistory $row): bool => $row->scope === $scope->value),
                );
                $this->keepNewestBuckets($scopeRows, $times, $format, $keep);
            }
            $this->keepBestBuckets($bandRows, $times, $format, $keep);
        }
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
     * Splits success rows into their age bands, keyed by the band's bucket format.
     *
     * The ladder is walked youngest first, so each row lands in the first band whose age it
     * has not yet reached: below the daily age it belongs to the keep-everything band, then
     * day, ISO week, month, and finally year. The band boundaries are computed once here
     * rather than per row.
     *
     * @param list<BackupHistory> $success Success rows with parsed timestamps
     * @param array<string, DateTimeImmutable> $times Parsed timestamps keyed by backup id
     * @param BackupRetentionPolicy $policy Retention ages
     * @param DateTimeImmutable $now Instant the ages are measured from
     * @return array<string, list<BackupHistory>> Rows keyed by band bucket format
     */
    private function groupByBand(
        array $success,
        array $times,
        BackupRetentionPolicy $policy,
        DateTimeImmutable $now,
    ): array {
        $ladder = [
            [$now->modify('-' . max(0, $policy->daily) . ' days'), self::BAND_KEEP_ALL],
            [$now->modify('-' . max(0, $policy->weekly) . ' weeks'), self::FORMAT_DAY],
            [$now->modify('-' . max(0, $policy->monthly) . ' months'), self::FORMAT_WEEK],
            [$now->modify('-' . max(0, $policy->yearly) . ' years'), self::FORMAT_MONTH],
        ];

        $bands = [];
        foreach ($success as $row) {
            $time = $times[$row->getId()];
            $format = self::FORMAT_YEAR;
            foreach ($ladder as [$boundary, $bandFormat]) {
                if ($time >= $boundary) {
                    $format = $bandFormat;

                    break;
                }
            }
            $bands[$format][] = $row;
        }

        return $bands;
    }

    /**
     * Keeps the newest row of every bucket in one band.
     *
     * @param list<BackupHistory> $rows Candidate rows of one band (single scope)
     * @param array<string, DateTimeImmutable> $times Parsed timestamps keyed by backup id
     * @param string $format Date format naming the band's bucket
     * @param array<string, true> $keep Keep-set to extend, keyed by backup id
     */
    private function keepNewestBuckets(array $rows, array $times, string $format, array &$keep): void
    {
        /** @var array<string, BackupHistory> $buckets */
        $buckets = [];
        foreach ($rows as $row) {
            $bucket = $times[$row->getId()]->format($format);
            if (!isset($buckets[$bucket]) || $times[$row->getId()] > $times[$buckets[$bucket]->getId()]) {
                $buckets[$bucket] = $row;
            }
        }

        foreach ($buckets as $row) {
            $keep[$row->getId()] = true;
        }
    }

    /**
     * Keeps the best-available representative of every bucket in one band.
     *
     * @param list<BackupHistory> $rows Candidate rows of one band (all scopes)
     * @param array<string, DateTimeImmutable> $times Parsed timestamps keyed by backup id
     * @param string $format Date format naming the band's bucket
     * @param array<string, true> $keep Keep-set to extend, keyed by backup id
     */
    private function keepBestBuckets(array $rows, array $times, string $format, array &$keep): void
    {
        /** @var array<string, BackupHistory> $buckets */
        $buckets = [];
        foreach ($rows as $row) {
            $bucket = $times[$row->getId()]->format($format);
            if (!isset($buckets[$bucket]) || $this->outranks($row, $buckets[$bucket], $times)) {
                $buckets[$bucket] = $row;
            }
        }

        foreach ($buckets as $row) {
            $keep[$row->getId()] = true;
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
            return new DateTimeImmutable($createdAt)->setTimezone($timezone);
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
