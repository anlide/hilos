<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use DateTimeZone;
use Hilos\Backup\BackupMetadata;
use Hilos\Backup\BackupPruner;
use Hilos\Backup\BackupRetentionPolicy;
use Hilos\Backup\BackupScope;
use Hilos\Backup\BackupStatus;
use Hilos\Runtime\State\Item\BackupHistory;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure rotation planner {@see BackupPruner::selectForDeletion()}.
 *
 * The planner never reads the clock or the filesystem, so every case is a fixed set of index
 * rows plus a policy and timezone, asserting exactly which backups the pass would delete.
 */
final class BackupPrunerTest extends TestCase
{
    private const string TIMEZONE = 'UTC';

    public function testCollapsesDuplicatesInABucketToTheNewest(): void
    {
        $rows = [
            $this->row('a', '2026-07-19T10:00:00+00:00', BackupScope::FULL),
            $this->row('b', '2026-07-19T12:00:00+00:00', BackupScope::FULL),
        ];

        $this->assertDoomed(['a'], $rows, $this->policy());
    }

    public function testKeepsEachScopeIndependentlyInTheSameBucket(): void
    {
        $rows = [
            $this->row('f', '2026-07-19T10:00:00+00:00', BackupScope::FULL),
            $this->row('s', '2026-07-19T09:00:00+00:00', BackupScope::SCHEMA_ONLY),
        ];

        $this->assertDoomed([], $rows, $this->policy(daily: 1));
    }

    public function testDailyDepthDropsTheOldestDayBuckets(): void
    {
        $rows = [
            $this->row('d1', '2026-07-19T10:00:00+00:00', BackupScope::FULL),
            $this->row('d2', '2026-07-18T10:00:00+00:00', BackupScope::FULL),
            $this->row('d3', '2026-07-17T10:00:00+00:00', BackupScope::FULL),
        ];

        $this->assertDoomed(['d3'], $rows, $this->policy(daily: 2));
    }

    public function testACoarserTierRetainsABackupBeyondTheDailyDepth(): void
    {
        $rows = [
            $this->row('a', '2026-07-19T10:00:00+00:00', BackupScope::SCHEMA_ONLY),
            $this->row('b', '2026-07-01T10:00:00+00:00', BackupScope::SCHEMA_ONLY),
            $this->row('c', '2026-06-15T10:00:00+00:00', BackupScope::SCHEMA_ONLY),
        ];

        // Daily keeps only the newest day (a); monthly (depth 2) additionally keeps June's
        // newest (c). The mid-July row (b) is collapsed out of both tiers.
        $this->assertDoomed(['b'], $rows, $this->policy(daily: 1, weekly: 0, monthly: 2, yearly: 0));
    }

    public function testPinnedBackupsAreNeverPruned(): void
    {
        $rows = [
            $this->row('a', '2026-07-19T10:00:00+00:00', BackupScope::FULL, keep: true),
            $this->row('b', '2026-07-19T12:00:00+00:00', BackupScope::FULL),
        ];

        $this->assertDoomed([], $rows, $this->policy());
    }

    public function testKeepsTheNewestErrorsUpToTheErrorCount(): void
    {
        $rows = [
            $this->row('e1', '2026-07-19T10:00:00+00:00', BackupScope::FULL, BackupStatus::ERROR),
            $this->row('e2', '2026-07-18T10:00:00+00:00', BackupScope::FULL, BackupStatus::ERROR),
            $this->row('e3', '2026-07-17T10:00:00+00:00', BackupScope::FULL, BackupStatus::ERROR),
        ];

        $this->assertDoomed(['e3'], $rows, $this->policy(errorCount: 2));
    }

    public function testErrorsNeverEnterTheRestoreGrids(): void
    {
        $rows = [
            $this->row('f', '2026-07-19T10:00:00+00:00', BackupScope::FULL),
            $this->row('e', '2026-07-18T10:00:00+00:00', BackupScope::FULL, BackupStatus::ERROR),
        ];

        // The full success backup is a grid representative; the error is not, and with the
        // error count at zero it is pruned rather than held by any tier.
        $this->assertDoomed(['e'], $rows, $this->policy(errorCount: 0));
    }

    public function testKeepsRowsWithAnUnparsableTimestamp(): void
    {
        $rows = [
            $this->row('good', '2026-07-19T10:00:00+00:00', BackupScope::FULL),
            $this->row('bad', '', BackupScope::FULL),
        ];

        $this->assertDoomed([], $rows, $this->policy());
    }

    public function testKeepsAReducedBackupCoveringAFullLessPeriod(): void
    {
        $rows = [
            $this->row('full-late', '2026-07-19T10:00:00+00:00', BackupScope::FULL),
            $this->row('seed-gap', '2026-07-18T10:00:00+00:00', BackupScope::SCHEMA_SEED),
            $this->row('full-early', '2026-07-17T10:00:00+00:00', BackupScope::FULL),
        ];

        // The middle day has no full backup, so the schema-seed row covering it is retained.
        $this->assertDoomed([], $rows, $this->policy());
    }

    /**
     * Asserts the planner would prune exactly the given ids.
     *
     * @param list<string> $expected Backup ids expected to be pruned
     * @param list<BackupHistory> $rows Index rows fed to the planner
     * @param BackupRetentionPolicy $policy Retention policy under test
     */
    private function assertDoomed(array $expected, array $rows, BackupRetentionPolicy $policy): void
    {
        $doomed = (new BackupPruner())->selectForDeletion($rows, $policy, new DateTimeZone(self::TIMEZONE));
        $ids = array_map(static fn(BackupHistory $row): string => $row->getId(), $doomed);
        sort($ids);
        $want = $expected;
        sort($want);

        $this->assertSame($want, $ids);
    }

    /**
     * Builds a retention policy with per-tier overrides (defaults mirror the catalog).
     *
     * @param int $daily Daily tier depth
     * @param int $weekly Weekly tier depth
     * @param int $monthly Monthly tier depth
     * @param int $yearly Yearly tier depth
     * @param int $errorCount Error records kept
     * @return BackupRetentionPolicy Policy for a test case
     */
    private function policy(
        int $daily = 45,
        int $weekly = 45,
        int $monthly = 45,
        int $yearly = 45,
        int $errorCount = 20,
    ): BackupRetentionPolicy {
        return new BackupRetentionPolicy($daily, $weekly, $monthly, $yearly, $errorCount);
    }

    /**
     * Builds one backup index row.
     *
     * @param string $id Backup id
     * @param string $createdAt ISO-8601 creation timestamp (blank for the unparsable case)
     * @param BackupScope $scope Backup scope
     * @param BackupStatus $status Terminal status
     * @param bool $keep Retention pin
     * @return BackupHistory Index row
     */
    private function row(
        string $id,
        string $createdAt,
        BackupScope $scope,
        BackupStatus $status = BackupStatus::SUCCESS,
        bool $keep = false,
    ): BackupHistory {
        return BackupHistory::fromMetadata(new BackupMetadata(
            $id,
            $createdAt,
            'test',
            $scope,
            [],
            0,
            0,
            $keep,
            $status,
        ));
    }
}
