<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Hilos\Backup\BackupCeilingGuard;
use Hilos\Backup\BackupCeilingSpare;
use Hilos\Backup\BackupMetadata;
use Hilos\Backup\BackupPruner;
use Hilos\Backup\BackupRetentionPolicy;
use Hilos\Backup\BackupScope;
use Hilos\Backup\BackupShipOutcome;
use Hilos\Backup\BackupStatus;
use Hilos\Runtime\State\Item\BackupHistory as StateBackupHistory;
use Hilos\Runtime\View\Item\BackupHistory;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure rotation planners {@see BackupPruner::selectForDeletion()} and
 * {@see BackupPruner::selectForCeiling()}.
 *
 * Neither planner reads the clock or the filesystem, so every case is a fixed set of index
 * rows plus a policy, a timezone, and the instant ages are measured from, asserting exactly
 * which backups the pass would delete. Every ladder fixture is dated relative to {@see NOW}
 * with the default policy in mind: the daily age lands on 2026-06-04, the weekly on 2025-09-07,
 * the monthly on 2022-10-19, and the yearly on 1981-07-19.
 *
 * The ceiling cases are read differently: they carry byte sizes and no clock at all, because the
 * pass runs over whatever the ladder already kept and only asks which rows are oldest.
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

    public function testCeilingOfZeroNeverDeletesAnything(): void
    {
        $rows = [
            $this->row('old', '2026-07-01T10:00:00+00:00', BackupScope::FULL, sizeBytes: 100),
            $this->row('new', '2026-07-10T10:00:00+00:00', BackupScope::FULL, sizeBytes: 100),
        ];

        // Zero is the default and it means "no ceiling", not "keep nothing".
        $this->assertCeilingDoomed([], $rows, 0);
    }

    public function testStoreWithinTheCeilingIsLeftAlone(): void
    {
        $rows = [
            $this->row('old', '2026-07-01T10:00:00+00:00', BackupScope::FULL, sizeBytes: 100),
            $this->row('new', '2026-07-10T10:00:00+00:00', BackupScope::FULL, sizeBytes: 100),
        ];

        // Exactly at the ceiling is inside it: the pass cuts to the number, never below it.
        $this->assertCeilingDoomed([], $rows, 200);
    }

    public function testDeletesTheOldestRowsUntilTheStoreFitsAndNoFurther(): void
    {
        $rows = [
            $this->row('old', '2026-07-01T10:00:00+00:00', BackupScope::FULL, sizeBytes: 100),
            $this->row('mid', '2026-07-05T10:00:00+00:00', BackupScope::FULL, sizeBytes: 100),
            $this->row('new', '2026-07-10T10:00:00+00:00', BackupScope::FULL, sizeBytes: 100),
        ];

        // 300 stored against a 250 ceiling: one deletion is enough, and 'mid' - a candidate the
        // pass had in hand - stays, because recent history is what an operator restores from.
        $this->assertCeilingDoomed(['old'], $rows, 250);
        $this->assertCeilingDoomed(['old', 'mid'], $rows, 150);
    }

    public function testNeverDeletesAPinnedRowEvenWhenItIsTheOldest(): void
    {
        $rows = [
            $this->row('pinned', '2026-06-01T10:00:00+00:00', BackupScope::FULL, keep: true, sizeBytes: 100),
            $this->row('old', '2026-07-01T10:00:00+00:00', BackupScope::FULL, sizeBytes: 100),
            $this->row('new', '2026-07-10T10:00:00+00:00', BackupScope::FULL, sizeBytes: 100),
        ];

        $this->assertCeilingDoomed(['old'], $rows, 250);
    }

    public function testKeepsTheNewestSuccessfulBackupOfEveryScope(): void
    {
        $rows = [
            $this->row('full-old', '2026-07-01T10:00:00+00:00', BackupScope::FULL, sizeBytes: 100),
            $this->row('full-new', '2026-07-02T10:00:00+00:00', BackupScope::FULL, sizeBytes: 100),
            $this->row('seed-old', '2026-07-03T10:00:00+00:00', BackupScope::SCHEMA_SEED, sizeBytes: 100),
            $this->row('seed-new', '2026-07-04T10:00:00+00:00', BackupScope::SCHEMA_SEED, sizeBytes: 100),
            $this->row('schema-old', '2026-07-05T10:00:00+00:00', BackupScope::SCHEMA_ONLY, sizeBytes: 100),
            $this->row('schema-new', '2026-07-06T10:00:00+00:00', BackupScope::SCHEMA_ONLY, sizeBytes: 100),
        ];

        // A ceiling below one archive must not thin the store down to zero restore points, so
        // each scope's newest survives however far over the store is.
        $this->assertCeilingDoomed(['full-old', 'seed-old', 'schema-old'], $rows, 1);
    }

    public function testErrorRowsAreNeverCeilingCandidates(): void
    {
        $rows = [
            $this->row('e', '2026-06-01T10:00:00+00:00', BackupScope::FULL, BackupStatus::ERROR, sizeBytes: 900),
            $this->row('old', '2026-07-01T10:00:00+00:00', BackupScope::FULL, sizeBytes: 100),
            $this->row('new', '2026-07-10T10:00:00+00:00', BackupScope::FULL, sizeBytes: 100),
        ];

        // The error row is the oldest and by far the largest, but it carries no archive: deleting
        // it would free nothing, so the ceiling takes the one real candidate and stops short.
        $this->assertCeilingDoomed(['old'], $rows, 500);
    }

    public function testRowsWithAnUnparsableTimestampAreNeverCeilingCandidates(): void
    {
        $rows = [
            $this->row('bad', '', BackupScope::FULL, sizeBytes: 100),
            $this->row('old', '2026-07-01T10:00:00+00:00', BackupScope::FULL, sizeBytes: 100),
            $this->row('new', '2026-07-10T10:00:00+00:00', BackupScope::FULL, sizeBytes: 100),
        ];

        // Same caution the ladder applies: a row that cannot be placed in time is never removed.
        $this->assertCeilingDoomed(['old'], $rows, 250);
    }

    public function testSparesArchivesThatHaveNotLeftTheMachineWhenShippingIsConfigured(): void
    {
        // 'never' and 'failed' are both older than 'shipped', so without the guard they would be
        // the first to go - and each is still the only copy of its backup that exists.
        $this->assertCeilingDoomed(['shipped'], $this->shippingRows(), 350, shippingConfigured: true);
    }

    public function testTakesAnUnshippedArchiveWhenShippingIsNotConfigured(): void
    {
        // Same fixture, no destination to ship to: 'never shipped' means nothing here, so the
        // ceiling falls back to plain age and the oldest row goes.
        $this->assertCeilingDoomed(['never'], $this->shippingRows(), 350);
    }

    public function testAnUnreachableCeilingDeletesEveryCandidateAndStops(): void
    {
        $rows = [
            $this->row('pinned', '2026-06-01T10:00:00+00:00', BackupScope::FULL, keep: true, sizeBytes: 100),
            $this->row('old', '2026-07-01T10:00:00+00:00', BackupScope::FULL, sizeBytes: 100),
            $this->row('mid', '2026-07-05T10:00:00+00:00', BackupScope::FULL, sizeBytes: 100),
            $this->row('new', '2026-07-10T10:00:00+00:00', BackupScope::FULL, sizeBytes: 100),
        ];

        // The ceiling is soft: it hands back everything it may delete and leaves the remaining
        // overflow to the caller to report, rather than reaching the number by force.
        $this->assertCeilingDoomed(['old', 'mid'], $rows, 1);
    }

    public function testOccupiedBytesSumsTheIndexRows(): void
    {
        $rows = [
            $this->row('a', '2026-07-01T10:00:00+00:00', BackupScope::FULL, sizeBytes: 100),
            $this->row('e', '2026-07-02T10:00:00+00:00', BackupScope::FULL, BackupStatus::ERROR, sizeBytes: 0),
            $this->row('b', '2026-07-03T10:00:00+00:00', BackupScope::SCHEMA_ONLY, sizeBytes: 25),
        ];

        $pruner = new BackupPruner();

        $this->assertSame(0, $pruner->occupiedBytes([]));
        $this->assertSame(125, $pruner->occupiedBytes($rows));
    }

    public function testAStoreThatFitsIsWeighedByNoGuardAtAll(): void
    {
        $rows = [
            $this->row('old', '2026-07-01T10:00:00+00:00', BackupScope::FULL, sizeBytes: 100),
            $this->row('new', '2026-07-10T10:00:00+00:00', BackupScope::FULL, sizeBytes: 100),
        ];

        // Both halves empty: nothing was spared where nothing was weighed.
        $this->assertCeilingDoomed([], $rows, 200);
        $this->assertCeilingSpared([], $rows, 200);
    }

    public function testEveryGuardIsReportedOnceWithItsRowsAndBytesHeaviestFirst(): void
    {
        // A ceiling of one against a store where every row is held by a different guard: nothing
        // is deletable, and the tally is what the operator gets instead.
        $this->assertCeilingDoomed([], $this->guardRows(), 1, shippingConfigured: true);
        $this->assertCeilingSpared(
            [
                [BackupCeilingGuard::NEWEST_OF_SCOPE, 1, 60],
                [BackupCeilingGuard::AWAITING_SHIPMENT, 1, 50],
                [BackupCeilingGuard::PINNED, 1, 40],
                [BackupCeilingGuard::UNDATED, 1, 30],
                [BackupCeilingGuard::UNKNOWN_SCOPE, 1, 20],
                [BackupCeilingGuard::ERROR_RECORD, 1, 10],
            ],
            $this->guardRows(),
            1,
            shippingConfigured: true,
        );
    }

    public function testARowHeldByTwoGuardsIsCountedOnceUnderTheLessRemovableOne(): void
    {
        $rows = [
            $this->row(
                'pin',
                '2026-07-01T10:00:00+00:00',
                BackupScope::FULL,
                keep: true,
                sizeBytes: 100,
                shipOutcome: BackupShipOutcome::FAILED,
            ),
            $this->row(
                'anchor',
                '2026-07-10T10:00:00+00:00',
                BackupScope::FULL,
                sizeBytes: 60,
                shipOutcome: BackupShipOutcome::OK,
            ),
        ];

        // A pin that never shipped is reported as a pin: repairing the channel would not free it,
        // and naming the guard that would still hold it is the whole point of the line.
        $this->assertCeilingSpared(
            [
                [BackupCeilingGuard::PINNED, 1, 100],
                [BackupCeilingGuard::NEWEST_OF_SCOPE, 1, 60],
            ],
            $rows,
            1,
            shippingConfigured: true,
        );
    }

    public function testUnshippedArchivesAreTalliedOnlyWhereShippingIsConfigured(): void
    {
        $this->assertCeilingSpared(
            [
                [BackupCeilingGuard::AWAITING_SHIPMENT, 2, 200],
                [BackupCeilingGuard::NEWEST_OF_SCOPE, 1, 100],
            ],
            $this->shippingRows(),
            350,
            shippingConfigured: true,
        );

        // No destination to ship to: the same two rows are plain candidates, and a candidate the
        // pass simply did not need is not something that held the disk.
        $this->assertCeilingSpared(
            [[BackupCeilingGuard::NEWEST_OF_SCOPE, 1, 100]],
            $this->shippingRows(),
            350,
        );
    }

    /**
     * Builds a store in which each of the six guards holds exactly one row, of its own size.
     *
     * @return list<BackupHistory> Index rows totalling 210 bytes
     */
    private function guardRows(): array
    {
        return [
            $this->row('err', '2026-07-01T10:00:00+00:00', BackupScope::FULL, BackupStatus::ERROR, sizeBytes: 10),
            $this->unknownScopeRow('unknown', '2026-07-02T10:00:00+00:00', 20),
            $this->row('undated', '', BackupScope::FULL, sizeBytes: 30),
            $this->row(
                'pinned',
                '2026-07-03T10:00:00+00:00',
                BackupScope::FULL,
                keep: true,
                sizeBytes: 40,
                shipOutcome: BackupShipOutcome::OK,
            ),
            $this->row(
                'awaiting',
                '2026-07-04T10:00:00+00:00',
                BackupScope::FULL,
                sizeBytes: 50,
                shipOutcome: BackupShipOutcome::FAILED,
            ),
            $this->row(
                'newest',
                '2026-07-05T10:00:00+00:00',
                BackupScope::FULL,
                sizeBytes: 60,
                shipOutcome: BackupShipOutcome::OK,
            ),
        ];
    }

    /**
     * Builds a row whose scope no longer resolves, the way a sidecar written by an older
     * installation reads today.
     *
     * @param string $id Backup id
     * @param string $createdAt ISO-8601 creation timestamp
     * @param int $sizeBytes Archive size in bytes
     * @return BackupHistory Index row with an unrecognized scope
     */
    private function unknownScopeRow(string $id, string $createdAt, int $sizeBytes): BackupHistory
    {
        $state = StateBackupHistory::fromMetadata(new BackupMetadata(
            $id,
            $createdAt,
            'test',
            BackupScope::FULL,
            [],
            $sizeBytes,
            0,
            false,
            BackupStatus::SUCCESS,
        ));
        $state->scope = 'retired-scope';

        return new BackupHistory($state);
    }

    /**
     * Builds the fixture both shipping cases read: two archives that never reached a receiver
     * and one that did, all older than the scope's newest backup.
     *
     * @return list<BackupHistory> Index rows totalling 400 bytes
     */
    private function shippingRows(): array
    {
        return [
            $this->row('never', '2026-07-01T10:00:00+00:00', BackupScope::FULL, sizeBytes: 100),
            $this->row(
                'failed',
                '2026-07-02T10:00:00+00:00',
                BackupScope::FULL,
                sizeBytes: 100,
                shipOutcome: BackupShipOutcome::FAILED,
            ),
            $this->row(
                'shipped',
                '2026-07-03T10:00:00+00:00',
                BackupScope::FULL,
                sizeBytes: 100,
                shipOutcome: BackupShipOutcome::OK,
            ),
            $this->row(
                'anchor',
                '2026-07-10T10:00:00+00:00',
                BackupScope::FULL,
                sizeBytes: 100,
                shipOutcome: BackupShipOutcome::OK,
            ),
        ];
    }

    /**
     * Asserts the ceiling pass would prune exactly the given ids, in that order.
     *
     * The order is part of the contract - oldest first - so it is asserted rather than sorted
     * away: a pass walking its candidates the other way round would eat the newest history.
     *
     * @param list<string> $expected Backup ids expected to be pruned, oldest first
     * @param list<BackupHistory> $rows Rows the ladder kept
     * @param int $ceilingBytes Total byte ceiling under test
     * @param bool $shippingConfigured Whether a shipping destination is configured
     */
    private function assertCeilingDoomed(
        array $expected,
        array $rows,
        int $ceilingBytes,
        bool $shippingConfigured = false,
    ): void {
        $plan = new BackupPruner()->selectForCeiling($rows, $ceilingBytes, $shippingConfigured);

        $this->assertSame(
            $expected,
            array_map(static fn(BackupHistory $row): string => $row->getId(), $plan->doomed),
        );
    }

    /**
     * Asserts the ceiling pass reports exactly these guards, in that order.
     *
     * The order is part of the contract too - heaviest first - because the log line built from it
     * is read top down by an operator asking what holds the disk.
     *
     * @param list<array{BackupCeilingGuard, int, int}> $expected Guard, row count and bytes, heaviest first
     * @param list<BackupHistory> $rows Rows the ladder kept
     * @param int $ceilingBytes Total byte ceiling under test
     * @param bool $shippingConfigured Whether a shipping destination is configured
     */
    private function assertCeilingSpared(
        array $expected,
        array $rows,
        int $ceilingBytes,
        bool $shippingConfigured = false,
    ): void {
        $plan = new BackupPruner()->selectForCeiling($rows, $ceilingBytes, $shippingConfigured);

        $this->assertSame(
            $expected,
            array_map(
                static fn(BackupCeilingSpare $spare): array => [$spare->guard, $spare->count, $spare->bytes],
                $plan->spared,
            ),
        );
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
        $doomed = new BackupPruner()->selectForDeletion(
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
     * @param int $maxTotalBytes Total byte ceiling for the store; 0 means no ceiling
     * @return BackupRetentionPolicy Policy for a test case
     */
    private function policy(
        int $daily = 45,
        int $weekly = 45,
        int $monthly = 45,
        int $yearly = 45,
        int $errorCount = 20,
        int $maxTotalBytes = 0,
    ): BackupRetentionPolicy {
        return new BackupRetentionPolicy($daily, $weekly, $monthly, $yearly, $errorCount, $maxTotalBytes);
    }

    /**
     * Builds one backup index row.
     *
     * @param string $id Backup id
     * @param string $createdAt ISO-8601 creation timestamp (blank for the unparsable case)
     * @param BackupScope $scope Backup scope
     * @param BackupStatus $status Terminal status
     * @param bool $keep Retention pin
     * @param int $sizeBytes Archive size in bytes, which is what the ceiling measures
     * @param ?BackupShipOutcome $shipOutcome Outcome of the last copy off the machine; null means never attempted
     * @return BackupHistory Index row
     */
    private function row(
        string $id,
        string $createdAt,
        BackupScope $scope,
        BackupStatus $status = BackupStatus::SUCCESS,
        bool $keep = false,
        int $sizeBytes = 0,
        ?BackupShipOutcome $shipOutcome = null,
    ): BackupHistory {
        $metadata = new BackupMetadata(
            $id,
            $createdAt,
            'test',
            $scope,
            [],
            $sizeBytes,
            0,
            $keep,
            $status,
        );
        if ($shipOutcome !== null) {
            // Only a successful copy stamps an instant, so a failed row carries the outcome alone.
            $shippedAt = $shipOutcome === BackupShipOutcome::OK ? $createdAt : null;
            $metadata = $metadata->withShipping($shippedAt, $shipOutcome, null);
        }
        $state = StateBackupHistory::fromMetadata($metadata);

        return new BackupHistory($state);
    }
}
