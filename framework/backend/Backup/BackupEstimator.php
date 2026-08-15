<?php

declare(strict_types=1);

namespace Hilos\Backup;

use Hilos\Runtime\View\Item\BackupHistory;

/**
 * BackupEstimator - how long a run about to start is expected to take.
 *
 * Shaped like {@see BackupSpaceGuard}: index rows and a scope in, a number out; no disk, no clock,
 * no runtime. It answers the one question a progress bar cannot be drawn without, and it answers
 * it from the only history the installation has - the backup index, which is a projection of the
 * sidecars on disk.
 *
 * The two runs are estimated differently because their histories are shaped differently. A create
 * run of one scope repeats a comparable amount of work, so the median duration of the recent ones
 * is the estimate. A restore is done from one particular archive, and archives differ in size, so
 * what carries over between runs is the speed - seconds per byte - which is then multiplied by the
 * size of the archive at hand. Both are smoothed over {@see BackupSpaceGuard::ESTIMATE_DEPTH} runs
 * of the same scope, and a run with nothing to learn from returns null: the surfaces then show the
 * phase without a percentage rather than a number nobody stands behind.
 */
final class BackupEstimator
{
    /**
     * How long a backup of this scope is expected to take, from the recent ones.
     *
     * @param list<BackupHistory> $rows Current backup index rows (all scopes and statuses)
     * @param BackupScope $scope Scope of the run being estimated
     * @return ?int Estimated seconds, or null when no recent run of the scope carries a duration
     */
    public static function createSeconds(array $rows, BackupScope $scope): ?int
    {
        $recent = self::recentRuns(
            $rows,
            $scope,
            static fn(BackupHistory $row): bool => $row->durationSeconds > 0,
            static fn(BackupHistory $row): string => $row->createdAt,
        );

        if ($recent === []) {
            return null;
        }

        $durations = array_map(static fn(BackupHistory $row): float => (float)$row->durationSeconds, $recent);

        return (int)round(self::median($durations));
    }

    /**
     * How long restoring an archive of the given size is expected to take, from the recent restores.
     *
     * @param list<BackupHistory> $rows Current backup index rows (all scopes and statuses)
     * @param BackupScope $scope Scope of the run being estimated
     * @param int $archiveBytes Size of the archive being restored
     * @return ?int Estimated seconds, or null when the archive has no size or no recent restore was recorded
     */
    public static function restoreSeconds(array $rows, BackupScope $scope, int $archiveBytes): ?int
    {
        if ($archiveBytes <= 0) {
            return null;
        }

        $recent = self::recentRuns(
            $rows,
            $scope,
            static fn(BackupHistory $row): bool => $row->restoreDurationSeconds > 0 && $row->sizeBytes > 0,
            static fn(BackupHistory $row): string => (string)$row->restoredAt,
        );

        if ($recent === []) {
            return null;
        }

        $rates = array_map(
            static fn(BackupHistory $row): float => $row->restoreDurationSeconds / $row->sizeBytes,
            $recent,
        );

        return (int)round(self::median($rates) * $archiveBytes);
    }

    /**
     * The newest successful runs of one scope that carry the data an estimate needs.
     *
     * Error rows never finished the work being timed, and a zero is a legacy sidecar or a run that
     * recorded nothing - "no data" rather than "instant", exactly as the space guard reads it.
     *
     * @param list<BackupHistory> $rows Current backup index rows
     * @param BackupScope $scope Scope to filter to
     * @param callable(BackupHistory): bool $carriesData Whether a row carries the figures being estimated from
     * @param callable(BackupHistory): string $recencyOf The row's instant for this estimate, newest first
     * @return list<BackupHistory> Up to {@see BackupSpaceGuard::ESTIMATE_DEPTH} newest usable rows of the scope
     */
    private static function recentRuns(
        array $rows,
        BackupScope $scope,
        callable $carriesData,
        callable $recencyOf,
    ): array {
        $matching = array_values(array_filter(
            $rows,
            static fn(BackupHistory $row): bool => $row->scope === $scope->value
                && BackupStatus::fromString($row->status) === BackupStatus::SUCCESS
                && $carriesData($row),
        ));

        usort($matching, static fn(BackupHistory $a, BackupHistory $b): int => strcmp($recencyOf($b), $recencyOf($a)));

        return array_slice($matching, 0, BackupSpaceGuard::ESTIMATE_DEPTH);
    }

    /**
     * The median of a non-empty list of measurements.
     *
     * @param list<float> $values Measurements (must be non-empty)
     * @return float Median value (the average of the two middle values for an even count)
     */
    private static function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $mid = intdiv($count, 2);

        if ($count % 2 === 1) {
            return $values[$mid];
        }

        return ($values[$mid - 1] + $values[$mid]) / 2;
    }
}
