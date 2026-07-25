<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use DateTimeImmutable;
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
 * rows plus a policy, a timezone, and the instant ages are measured from, asserting exactly
 * which backups the pass would delete. Every fixture is dated relative to {@see NOW} with the
 * default policy in mind: the daily age lands on 2026-06-04, the weekly on 2025-09-07, the
 * monthly on 2022-10-19, and the yearly on 1981-07-19.
 */
final class BackupPrunerTest extends TestCase
{
    private const string TIMEZONE = 'UTC';

    /** The instant every fixture's age is measured from. */
    private const string NOW = '2026-07-19T12:00:00+00:00';

    public function testKeepsEveryBackupYoungerThanTheDailyAge(): void
    {
        $rows = [
            $this->row('a', '2026-07-19T10:00:00+00:00', BackupScope::FULL),
            $this->row('b', '2026-07-19T12:00:00+00:00', BackupScope::FULL),
        ];

        // Two backups taken the same day, minutes apart: a manual backup is a deliberate act
        // and must not be evicted by the next one on the same day.
        $this->assertDoomed([], $rows, $this->policy());
    }

    public function testCollapsesADayToItsNewestOncePastTheDailyAge(): void
    {
        $rows = [
            $this->row('old-morning', '2026-05-28T10:00:00+00:00', BackupScope::FULL),
            $this->row('old-evening', '2026-05-28T20:00:00+00:00', BackupScope::FULL),
        ];

        $this->assertDoomed(['old-morning'], $rows, $this->policy());
    }

    public function testKeepsEachScopeIndependentlyInTheSameBucket(): void
    {
        $rows = [
            $this->row('f', '2026-05-28T10:00:00+00:00', BackupScope::FULL),
            $this->row('s', '2026-05-28T09:00:00+00:00', BackupScope::SCHEMA_ONLY),
        ];

        $this->assertDoomed([], $rows, $this->policy());
    }

    public function testCollapsesAWeekToItsNewestOncePastTheWeeklyAge(): void
    {
        $rows = [
            // Both are past the weekly age (2025-09-07) and share ISO week 2025-32.
            $this->row('w-early', '2025-08-04T10:00:00+00:00', BackupScope::FULL),
            $this->row('w-late', '2025-08-06T10:00:00+00:00', BackupScope::FULL),
        ];

        $this->assertDoomed(['w-early'], $rows, $this->policy());
    }

    public function testKeepsBothWhenTheSameTwoDaysAreStillInTheDailyBand(): void
    {
        $rows = [
            // Same pair as the weekly case, but young enough that the day is the bucket.
            $this->row('d-early', '2026-05-28T10:00:00+00:00', BackupScope::FULL),
            $this->row('d-late', '2026-05-30T10:00:00+00:00', BackupScope::FULL),
        ];

        $this->assertDoomed([], $rows, $this->policy());
    }

    public function testCollapsesAMonthToItsNewestOncePastTheMonthlyAge(): void
    {
        $rows = [
            $this->row('m-early', '2022-05-05T10:00:00+00:00', BackupScope::FULL),
            $this->row('m-late', '2022-05-25T10:00:00+00:00', BackupScope::FULL),
        ];

        $this->assertDoomed(['m-early'], $rows, $this->policy());
    }

    public function testCollapsesAYearToItsNewestOncePastTheYearlyAge(): void
    {
        $rows = [
            $this->row('y-early', '1975-01-01T10:00:00+00:00', BackupScope::FULL),
            $this->row('y-late', '1975-12-31T10:00:00+00:00', BackupScope::FULL),
            $this->row('y-other', '1974-06-01T10:00:00+00:00', BackupScope::FULL),
        ];

        $this->assertDoomed(['y-early'], $rows, $this->policy());
    }

    public function testTheLadderCoarsensWithAgeInOnePass(): void
    {
        $rows = [
            // Fresh: both survive.
            $this->row('fresh-1', '2026-07-19T09:00:00+00:00', BackupScope::FULL),
            $this->row('fresh-2', '2026-07-19T11:00:00+00:00', BackupScope::FULL),
            // Daily band: same day collapses.
            $this->row('day-old', '2026-05-28T08:00:00+00:00', BackupScope::FULL),
            $this->row('day-new', '2026-05-28T18:00:00+00:00', BackupScope::FULL),
            // Weekly band: two days of one ISO week collapse to the newest.
            $this->row('week-old', '2025-08-04T10:00:00+00:00', BackupScope::FULL),
            $this->row('week-new', '2025-08-06T10:00:00+00:00', BackupScope::FULL),
        ];

        $this->assertDoomed(['day-old', 'week-old'], $rows, $this->policy());
    }

    public function testAZeroDailyAgeThinsEvenTodaysBackups(): void
    {
        $rows = [
            $this->row('a', '2026-07-19T09:00:00+00:00', BackupScope::FULL),
            $this->row('b', '2026-07-19T11:00:00+00:00', BackupScope::FULL),
        ];

        $this->assertDoomed(['a'], $rows, $this->policy(daily: 0));
    }

    public function testPinnedBackupsAreNeverPruned(): void
    {
        $rows = [
            $this->row('a', '2026-05-28T10:00:00+00:00', BackupScope::FULL, keep: true),
            $this->row('b', '2026-05-28T12:00:00+00:00', BackupScope::FULL),
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

        // Error records are a plain newest-N list: the age ladder never applies to them.
        $this->assertDoomed(['e3'], $rows, $this->policy(errorCount: 2));
    }

    public function testErrorsNeverEnterTheRestoreGrids(): void
    {
        $rows = [
            $this->row('f', '2026-07-19T10:00:00+00:00', BackupScope::FULL),
            $this->row('e', '2026-07-18T10:00:00+00:00', BackupScope::FULL, BackupStatus::ERROR),
        ];

        // The success backup is inside the daily age; the error is not held by any band, and
        // with the error count at zero nothing retains it.
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
            $this->row('full-late', '2026-05-30T10:00:00+00:00', BackupScope::FULL),
            $this->row('seed-gap', '2026-05-29T10:00:00+00:00', BackupScope::SCHEMA_SEED),
            $this->row('full-early', '2026-05-28T10:00:00+00:00', BackupScope::FULL),
        ];

        // All three are in the daily band on three different days; the middle day has no full
        // backup, so the schema-seed row covering it is the timeline's representative.
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
        $doomed = (new BackupPruner())->selectForDeletion(
            $rows,
            $policy,
            new DateTimeZone(self::TIMEZONE),
            new DateTimeImmutable(self::NOW),
        );
        $ids = array_map(static fn(BackupHistory $row): string => $row->getId(), $doomed);
        sort($ids);
        $want = $expected;
        sort($want);

        $this->assertSame($want, $ids);
    }

    /**
     * Builds a retention policy with per-tier overrides (defaults mirror the catalog).
     *
     * @param int $daily Age in days from which a day collapses to its newest backup
     * @param int $weekly Age in weeks from which a week collapses to its newest backup
     * @param int $monthly Age in months from which a month collapses to its newest backup
     * @param int $yearly Age in years from which a year collapses to its newest backup
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
