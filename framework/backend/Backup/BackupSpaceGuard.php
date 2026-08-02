<?php

declare(strict_types=1);

namespace Hilos\Backup;

use Hilos\Runtime\State\Item\BackupHistory;

/**
 * BackupSpaceGuard - the pure admit/refuse decision for a backup about to run.
 *
 * Shaped like {@see BackupPruner::selectForDeletion()}: free bytes, the history rows, the scope,
 * and the policy in; a {@see BackupSpaceDecision} with a ready-made reason out. It touches no disk
 * and reads no clock, so the whole gate is unit-tested without a filesystem - only the agent calls
 * `disk_free_space()` and hands the result here.
 *
 * The estimate is the true uncompressed peak, not the archive size: the median dump volume plus the
 * median archive size over the last few successful runs of the SAME scope that carry a dump size,
 * multiplied by the policy margin (the expanded dump and the growing archive coexist during tar, so
 * both are summed). The absolute floor applies in every branch, including when there is no estimate,
 * so a sharply grown database or a first backup is still held to a free-space minimum. When no run
 * of the scope has recorded a dump size yet, the policy flag decides between proceeding (the default,
 * so a clean install is never blocked) and refusing.
 */
final class BackupSpaceGuard
{
    /**
     * How many recent successful runs the medians are taken over. A code constant, not env: this is
     * a statistical smoothing depth, not a deployment policy, and HIL-277's ETA reuses the same one.
     */
    private const int ESTIMATE_DEPTH = 5;

    /**
     * Decides whether a backup of the given scope may run against the measured free space.
     *
     * @param int $freeBytes Free bytes measured on the backup filesystem
     * @param list<BackupHistory> $rows Current backup index rows (all scopes and statuses)
     * @param BackupScope $scope Scope of the run being decided
     * @param BackupSpacePolicy $policy Margin, floor, and no-estimate policy
     * @return BackupSpaceDecision Admit/refuse verdict carrying the required bytes and a reason
     */
    public function decide(
        int $freeBytes,
        array $rows,
        BackupScope $scope,
        BackupSpacePolicy $policy,
    ): BackupSpaceDecision {
        $floor = max(0, $policy->minFreeBytes);
        $recent = $this->recentSizedRuns($rows, $scope);

        if ($recent === []) {
            return $this->decideWithoutEstimate($freeBytes, $scope, $floor, $policy);
        }

        $dumps = array_map(static fn(BackupHistory $row): int => $row->dumpBytes, $recent);
        $sizes = array_map(static fn(BackupHistory $row): int => $row->sizeBytes, $recent);
        $estimate = (int)round(($this->median($dumps) + $this->median($sizes)) * $policy->spaceMargin);
        $required = max($estimate, $floor);

        if ($freeBytes >= $required) {
            return new BackupSpaceDecision(true, $required, $freeBytes);
        }

        // The binding requirement decides which reason is told: the estimate when it dominates, the
        // floor when a small estimate is lifted by it, so the operator reads the number that refused.
        $reason = $estimate >= $floor
            ? sprintf(
                'Insufficient free space for %s backup: need %d bytes (estimated from %d recent runs × %.2f margin), %d bytes free',
                $scope->value,
                $required,
                count($recent),
                $policy->spaceMargin,
                $freeBytes,
            )
            : $this->floorReason($scope, $floor, $freeBytes);

        return new BackupSpaceDecision(false, $required, $freeBytes, $reason);
    }

    /**
     * Decides a run for a scope with no prior sized run: the policy flag, then the floor.
     *
     * @param int $freeBytes Free bytes measured on the backup filesystem
     * @param BackupScope $scope Scope of the run being decided
     * @param int $floor Absolute free-space floor in bytes (already clamped non-negative)
     * @param BackupSpacePolicy $policy Margin, floor, and no-estimate policy
     * @return BackupSpaceDecision Admit/refuse verdict
     */
    private function decideWithoutEstimate(
        int $freeBytes,
        BackupScope $scope,
        int $floor,
        BackupSpacePolicy $policy,
    ): BackupSpaceDecision {
        if ($policy->refuseWithoutEstimate) {
            $reason = sprintf(
                'Refusing %s backup: no prior successful run carries a dump size to estimate from '
                . 'and BACKUP_REFUSE_WITHOUT_ESTIMATE is set (%d bytes free)',
                $scope->value,
                $freeBytes,
            );

            return new BackupSpaceDecision(false, $floor, $freeBytes, $reason);
        }

        if ($freeBytes < $floor) {
            return new BackupSpaceDecision(false, $floor, $freeBytes, $this->floorReason($scope, $floor, $freeBytes));
        }

        return new BackupSpaceDecision(true, $floor, $freeBytes);
    }

    /**
     * The newest sized successful runs of one scope, most recent first, capped at the estimate depth.
     *
     * A run counts only when it succeeded and carries a non-zero dump size: error rows never sized a
     * dump, and a zero is a legacy sidecar or the in-archive stub, treated as "no data".
     *
     * @param list<BackupHistory> $rows Current backup index rows
     * @param BackupScope $scope Scope to filter to
     * @return list<BackupHistory> Up to {@see ESTIMATE_DEPTH} newest sized success rows of the scope
     */
    private function recentSizedRuns(array $rows, BackupScope $scope): array
    {
        $matching = array_values(array_filter(
            $rows,
            static fn(BackupHistory $row): bool => $row->scope === $scope->value
                && BackupStatus::fromString($row->status) === BackupStatus::SUCCESS
                && $row->dumpBytes > 0,
        ));

        usort($matching, static fn(BackupHistory $a, BackupHistory $b): int => strcmp($b->createdAt, $a->createdAt));

        return array_slice($matching, 0, self::ESTIMATE_DEPTH);
    }

    /**
     * The median of a non-empty list of byte counts.
     *
     * @param list<int> $values Byte counts (must be non-empty)
     * @return float Median value (the average of the two middle values for an even count)
     */
    private function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $mid = intdiv($count, 2);

        if ($count % 2 === 1) {
            return (float)$values[$mid];
        }

        return ($values[$mid - 1] + $values[$mid]) / 2;
    }

    /**
     * The failure reason for a run held back by the absolute floor.
     *
     * @param BackupScope $scope Scope of the run
     * @param int $floor Absolute free-space floor in bytes
     * @param int $freeBytes Free bytes measured
     * @return string Ready-made failure reason
     */
    private function floorReason(BackupScope $scope, int $floor, int $freeBytes): string
    {
        return sprintf(
            'Insufficient free space for %s backup: need %d bytes (minimum free-space floor), %d bytes free',
            $scope->value,
            $floor,
            $freeBytes,
        );
    }
}
