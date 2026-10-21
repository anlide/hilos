<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Hilos\Backup\BackupCeilingGuard;
use Hilos\Backup\BackupCeilingSpare;
use Hilos\Backup\BackupCreator;
use Hilos\Backup\BackupDeletionMarker;
use Hilos\Backup\BackupHistoryScanner;
use Hilos\Backup\BackupMetadata;
use Hilos\Backup\BackupPruner;
use Hilos\Backup\BackupRetentionPolicy;
use Hilos\Backup\BackupScope;
use Hilos\Backup\BackupShipOutcome;
use Hilos\Backup\BackupStatus;
use Hilos\Constants\TimeConstants;
use Hilos\Runtime\State\Item\BackupHistory as StateBackupHistory;
use Hilos\Runtime\View\Item\BackupHistory;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure rotation planners {@see BackupPruner::selectForDeletion()} and
 * {@see BackupPruner::selectForCeiling()}, and for the shared physical-delete path
 * {@see BackupPruner::deleteStored()}.
 *
 * Neither planner reads the clock or the filesystem, so every case is a fixed set of index
 * rows plus a policy, a timezone, and the instant ages are measured from, asserting exactly
 * which backups the pass would delete. Every ladder fixture is dated relative to {@see NOW}
 * with the default policy in mind: the daily age lands on 2026-06-04, the weekly on 2025-09-07,
 * the monthly on 2022-10-19, and the yearly on 1981-07-19.
 *
 * The Warsaw cases run the same ladder on a project clock while the process clock stays UTC, as
 * it does in the daemon. Their rows are absolute UTC strings with the local wall time beside
 * them, so each pair visibly straddles a local midnight, Monday, first of the month or new year.
 *
 * The ceiling cases are read differently: they carry byte sizes and no clock at all, because the
 * pass runs over whatever the ladder already kept and only asks which rows are oldest.
 *
 * The delete-stored cases do touch the filesystem: they assert the marker left beside a shipped
 * pair, and that a backup never shipped leaves none.
 */
final class BackupPrunerTest extends TestCase
{
    private const string TIMEZONE = 'UTC';

    /** Project time zone of the Warsaw cases: +01:00 in winter, +02:00 in summer. */
    private const string WARSAW = 'Europe/Warsaw';

    /** The instant every fixture's age is measured from. */
    private const string NOW = '2026-07-19T12:00:00+00:00';

    private string $previousProcessZone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousProcessZone = date_default_timezone_get();
        date_default_timezone_set(TimeConstants::UTC_TIME_ZONE);
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->previousProcessZone);
        parent::tearDown();
    }

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

    public function testARowJustPastLocalMidnightJoinsTheLocalDay(): void
    {
        $rows = [
            $this->row('wed-late', '2026-05-27T21:30:00+00:00', BackupScope::FULL), // Wed 23:30 CEST
            $this->row('thu-early', '2026-05-27T22:30:00+00:00', BackupScope::FULL), // Thu 00:30 CEST
            $this->row('thu-noon', '2026-05-28T10:00:00+00:00', BackupScope::FULL), // Thu 12:00 CEST
        ];

        // The daily band: in Warsaw 'thu-early' is Thursday and loses to the Thursday noon row;
        // in UTC it is still Wednesday and takes Wednesday from 'wed-late'.
        $this->assertDoomedInWarsaw(['thu-early'], $rows, $this->policy());
        $this->assertDoomed(['wed-late'], $rows, $this->policy());
    }

    public function testARowJustPastTheLocalMondayJoinsTheLocalIsoWeek(): void
    {
        $rows = [
            $this->row('sun-late', '2025-01-12T22:30:00+00:00', BackupScope::FULL), // Sun 23:30 CET, week 2025-02
            $this->row('mon-early', '2025-01-12T23:30:00+00:00', BackupScope::FULL), // Mon 00:30 CET, week 2025-03
            $this->row('mon-noon', '2025-01-13T11:00:00+00:00', BackupScope::FULL), // Mon 12:00 CET, week 2025-03
        ];

        // The weekly band: the ISO week begins at the local Monday, an hour before the UTC one.
        $this->assertDoomedInWarsaw(['mon-early'], $rows, $this->policy());
        $this->assertDoomed(['sun-late'], $rows, $this->policy());
    }

    public function testARowJustPastTheLocalFirstOfTheMonthJoinsTheLocalMonth(): void
    {
        $rows = [
            $this->row('mar-late', '2021-03-31T21:30:00+00:00', BackupScope::FULL), // 31 Mar 23:30 CEST
            $this->row('apr-early', '2021-03-31T22:30:00+00:00', BackupScope::FULL), // 1 Apr 00:30 CEST
            $this->row('apr-mid', '2021-04-15T10:00:00+00:00', BackupScope::FULL), // 15 Apr 12:00 CEST
        ];

        // The monthly band: April begins in Warsaw two hours before it begins in UTC.
        $this->assertDoomedInWarsaw(['apr-early'], $rows, $this->policy());
        $this->assertDoomed(['mar-late'], $rows, $this->policy());
    }

    public function testARowJustPastTheLocalNewYearJoinsTheLocalYear(): void
    {
        $rows = [
            $this->row('dec-late', '1974-12-31T22:30:00+00:00', BackupScope::FULL), // 31 Dec 1974 23:30 CET
            $this->row('jan-early', '1974-12-31T23:30:00+00:00', BackupScope::FULL), // 1 Jan 1975 00:30 CET
            $this->row('jun', '1975-06-01T10:00:00+00:00', BackupScope::FULL), // 1 Jun 1975 11:00 CET
        ];

        // The yearly band: 1975 begins in Warsaw an hour before it begins in UTC.
        $this->assertDoomedInWarsaw(['jan-early'], $rows, $this->policy());
        $this->assertDoomed(['dec-late'], $rows, $this->policy());
    }

    public function testTheLongAutumnDayHoldsBothHalfPastTwosAndKeepsTheLaterInstant(): void
    {
        $now = '2026-10-27T11:00:00+00:00';
        $rows = [
            $this->row('day-start', '2026-10-24T22:00:00+00:00', BackupScope::FULL), // 25 Oct 00:00 CEST
            $this->row('summer-half-past-two', '2026-10-25T00:30:00+00:00', BackupScope::FULL), // 25 Oct 02:30 CEST
            $this->row('winter-half-past-two', '2026-10-25T01:30:00+00:00', BackupScope::FULL), // 25 Oct 02:30 CET
            $this->row('next-day-start', '2026-10-25T23:00:00+00:00', BackupScope::FULL), // 26 Oct 00:00 CET
        ];

        // 25 October runs 25 hours in Warsaw, from 22:00Z to 23:00Z the next day, and both 02:30
        // wall times fall in it. They read the same on the clock, so the representative is the
        // later instant - listed second, so a wall-clock tie would have kept the first.
        $this->assertDoomedInWarsaw(['day-start', 'summer-half-past-two'], $rows, $this->policy(daily: 1), $now);
        $this->assertDoomedOn(
            ['summer-half-past-two', 'winter-half-past-two'],
            $rows,
            $this->policy(daily: 1),
            new DateTimeZone(self::TIMEZONE),
            new DateTimeImmutable($now),
        );
    }

    public function testTheDailyAgeAcrossTheSpringChangeIsTwentyThreeHours(): void
    {
        // Sunday 29 March 12:00 CEST; one calendar day back is Saturday 12:00 CET, 23 hours ago.
        $now = '2026-03-29T10:00:00+00:00';
        $rows = [
            $this->row('sat-morning', '2026-03-28T08:00:00+00:00', BackupScope::FULL), // Sat 09:00 CET
            $this->row('sat-past', '2026-03-28T10:45:00+00:00', BackupScope::FULL), // Sat 11:45 CET, 23h15m ago
            $this->row('sat-at', '2026-03-28T11:00:00+00:00', BackupScope::FULL), // Sat 12:00 CET, exactly a day ago
        ];

        // 'sat-past' is not yet 24 hours old but is past the calendar day, so it is thinned with
        // the morning row; 'sat-at' sits on the age and stays in the untouched band.
        $this->assertDoomedInWarsaw(['sat-morning'], $rows, $this->policy(daily: 1), $now);
    }

    public function testTheDailyAgeAcrossTheAutumnChangeIsTwentyFiveHours(): void
    {
        // Sunday 25 October 12:00 CET; one calendar day back is Saturday 12:00 CEST, 25 hours ago.
        $now = '2026-10-25T11:00:00+00:00';
        $rows = [
            $this->row('sat-morning', '2026-10-24T08:00:00+00:00', BackupScope::FULL), // Sat 10:00 CEST
            $this->row('sat-before', '2026-10-24T09:45:00+00:00', BackupScope::FULL), // Sat 11:45 CEST, 25h15m ago
            $this->row('sat-at', '2026-10-24T10:00:00+00:00', BackupScope::FULL), // Sat 12:00 CEST, exactly a day ago
        ];

        // 'sat-at' is already 25 hours old but no older than the calendar day, so it is not thinned;
        // the two rows before it share Saturday and the morning one goes.
        $this->assertDoomedInWarsaw(['sat-morning'], $rows, $this->policy(daily: 1), $now);
    }

    public function testTheWeeklyAgeAcrossTheSpringChangeIsAnHourShortOfSevenFullDays(): void
    {
        // Wednesday 1 April 12:00 CEST; one calendar week back is 25 March 12:00 CET, 167 hours ago.
        $now = '2026-04-01T10:00:00+00:00';
        $rows = [
            $this->row('mon', '2026-03-23T10:00:00+00:00', BackupScope::FULL), // Mon 11:00 CET, week 2026-13
            $this->row('wed-past', '2026-03-25T10:45:00+00:00', BackupScope::FULL), // Wed 11:45 CET, 167h15m ago
            $this->row('wed-at', '2026-03-25T11:00:00+00:00', BackupScope::FULL), // Wed 12:00 CET, exactly a week ago
        ];

        // 'wed-past' is younger than 168 hours yet past the calendar week, so ISO week 2026-13
        // keeps it over Monday; 'wed-at' sits on the age and stays a day of its own.
        $this->assertDoomedInWarsaw(['mon'], $rows, $this->policy(daily: 0, weekly: 1), $now);
    }

    public function testTheWeeklyAgeAcrossTheAutumnChangeIsAnHourOverSevenFullDays(): void
    {
        // Wednesday 28 October 12:00 CET; one calendar week back is 21 October 12:00 CEST, 169 hours ago.
        $now = '2026-10-28T11:00:00+00:00';
        $rows = [
            $this->row('mon', '2026-10-19T10:00:00+00:00', BackupScope::FULL), // Mon 12:00 CEST, week 2026-43
            $this->row('wed-before', '2026-10-21T09:45:00+00:00', BackupScope::FULL), // Wed 11:45 CEST, 169h15m ago
            $this->row('wed-at', '2026-10-21T10:00:00+00:00', BackupScope::FULL), // Wed 12:00 CEST, exactly a week ago
        ];

        // 'wed-at' is already 169 hours old but no older than the calendar week, so it stays a day
        // of its own; 'wed-before' is past it and keeps ISO week 2026-43 over Monday.
        $this->assertDoomedInWarsaw(['mon'], $rows, $this->policy(daily: 0, weekly: 1), $now);
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

    public function testDeleteStoredWritesAMarkerBesideAShippedPairAndTakesThePairAway(): void
    {
        $row = $this->row(
            'a',
            '2026-07-19T10:00:00+00:00',
            BackupScope::FULL,
            shipOutcome: BackupShipOutcome::OK,
        );
        [$root, $scopeDir, $base, $archive, $sidecar] = $this->storePair($row);

        try {
            $this->assertTrue(new BackupPruner()->deleteStored($row, $root));
            $this->assertFileDoesNotExist($archive);
            $this->assertFileDoesNotExist($sidecar);
            $this->assertFileExists(BackupDeletionMarker::path($scopeDir, $base));
            $this->assertSame([$base], BackupDeletionMarker::owed($scopeDir));
        } finally {
            $this->removeStoreRoot($root);
        }
    }

    public function testDeleteStoredWritesAMarkerWhenTheLastCopyFailed(): void
    {
        // A failed last attempt still describes a copy that is over there: the marker is owed
        // whenever shipping was attempted, not only when it last succeeded.
        $row = $this->row(
            'a',
            '2026-07-19T10:00:00+00:00',
            BackupScope::FULL,
            shipOutcome: BackupShipOutcome::FAILED,
        );
        [$root, $scopeDir, $base] = $this->storePair($row);

        try {
            $this->assertTrue(new BackupPruner()->deleteStored($row, $root));
            $this->assertSame([$base], BackupDeletionMarker::owed($scopeDir));
        } finally {
            $this->removeStoreRoot($root);
        }
    }

    public function testDeleteStoredWritesNoMarkerWhenTheBackupWasNeverShipped(): void
    {
        $row = $this->row('a', '2026-07-19T10:00:00+00:00', BackupScope::FULL);
        [$root, $scopeDir, $base, $archive, $sidecar] = $this->storePair($row);

        try {
            $this->assertTrue(new BackupPruner()->deleteStored($row, $root));
            $this->assertFileDoesNotExist($archive);
            $this->assertFileDoesNotExist($sidecar);
            $this->assertFileDoesNotExist(BackupDeletionMarker::path($scopeDir, $base));
            $this->assertSame([], BackupDeletionMarker::owed($scopeDir));
        } finally {
            $this->removeStoreRoot($root);
        }
    }

    public function testDeleteStoredReturnsFalseWhenTheMarkerCannotBeWritten(): void
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hilos-pruner-missing-' . uniqid('', true);
        if (!mkdir($root, 0755, true) && !is_dir($root)) {
            $this->fail("Could not create fixture directory: {$root}");
        }

        try {
            $row = $this->row(
                'a',
                '2026-07-19T10:00:00+00:00',
                BackupScope::FULL,
                shipOutcome: BackupShipOutcome::OK,
            );
            $this->assertFalse(new BackupPruner()->deleteStored($row, $root));
        } finally {
            rmdir($root);
        }
    }

    /**
     * Lays a stored archive/sidecar pair under a fresh temp root for {@see BackupPruner::deleteStored()}.
     *
     * @param BackupHistory $row Index row whose files to place
     * @return array{string, string, string, string, string} Root, scope directory, base name, archive path, sidecar path
     */
    private function storePair(BackupHistory $row): array
    {
        $scope = BackupScope::fromString($row->scope);
        $this->assertNotNull($scope);
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hilos-pruner-store-' . uniqid('', true);
        $scopeDir = $root . '/' . $scope->value;
        if (!mkdir($scopeDir, 0755, true) && !is_dir($scopeDir)) {
            $this->fail("Could not create fixture directory: {$scopeDir}");
        }

        $base = BackupCreator::archiveBaseName($row->getId(), $row->env, $scope);
        $archive = $scopeDir . '/' . $base . BackupHistoryScanner::ARCHIVE_EXTENSION;
        $sidecar = $scopeDir . '/' . $base . BackupHistoryScanner::SIDECAR_EXTENSION;
        file_put_contents($archive, 'archive');
        file_put_contents($sidecar, '{}');

        return [$root, $scopeDir, $base, $archive, $sidecar];
    }

    /**
     * Removes a temp store created by {@see storePair()}.
     *
     * @param string $root Absolute path of the temp backup root
     */
    private function removeStoreRoot(string $root): void
    {
        foreach (glob($root . '/*') ?: [] as $scopeDir) {
            if (!is_dir($scopeDir)) {
                continue;
            }
            foreach (glob($scopeDir . '/{,.}*', GLOB_BRACE) ?: [] as $entry) {
                if (is_file($entry)) {
                    unlink($entry);
                }
            }
            rmdir($scopeDir);
        }
        if (is_dir($root)) {
            rmdir($root);
        }
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
        ), null);
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
     * Asserts the planner would prune exactly the given ids on the UTC clock at {@see NOW}.
     *
     * @param list<string> $expected Backup ids expected to be pruned
     * @param list<BackupHistory> $rows Index rows fed to the planner
     * @param BackupRetentionPolicy $policy Retention policy under test
     */
    private function assertDoomed(array $expected, array $rows, BackupRetentionPolicy $policy): void
    {
        $this->assertDoomedOn(
            $expected,
            $rows,
            $policy,
            new DateTimeZone(self::TIMEZONE),
            new DateTimeImmutable(self::NOW),
        );
    }

    /**
     * Asserts the planner would prune exactly the given ids on the Warsaw clock.
     *
     * The instant is handed over twice: once in UTC, as the agent reads its clock on a UTC
     * process, and once as the same moment in Warsaw. Both must give the same selection, so
     * the ladder may not depend on which zone the caller's instant happens to carry.
     *
     * @param list<string> $expected Backup ids expected to be pruned
     * @param list<BackupHistory> $rows Index rows fed to the planner
     * @param BackupRetentionPolicy $policy Retention policy under test
     * @param string $now ISO-8601 instant the ages are measured from
     */
    private function assertDoomedInWarsaw(
        array $expected,
        array $rows,
        BackupRetentionPolicy $policy,
        string $now = self::NOW,
    ): void {
        $warsaw = new DateTimeZone(self::WARSAW);
        $utcNow = new DateTimeImmutable($now)->setTimezone(new DateTimeZone(TimeConstants::UTC_TIME_ZONE));

        $this->assertDoomedOn($expected, $rows, $policy, $warsaw, $utcNow);
        $this->assertDoomedOn($expected, $rows, $policy, $warsaw, $utcNow->setTimezone($warsaw));
    }

    /**
     * Asserts the planner would prune exactly the given ids on the given clock.
     *
     * @param list<string> $expected Backup ids expected to be pruned
     * @param list<BackupHistory> $rows Index rows fed to the planner
     * @param BackupRetentionPolicy $policy Retention policy under test
     * @param DateTimeZone $timezone Timezone the planner derives buckets and ages in
     * @param DateTimeImmutable $now Instant the ages are measured from
     */
    private function assertDoomedOn(
        array $expected,
        array $rows,
        BackupRetentionPolicy $policy,
        DateTimeZone $timezone,
        DateTimeImmutable $now,
    ): void {
        $doomed = new BackupPruner()->selectForDeletion($rows, $policy, $timezone, $now);
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
            $metadata = $metadata->withShipping($shippedAt, $shipOutcome, null, null);
        }
        $state = StateBackupHistory::fromMetadata($metadata, null);

        return new BackupHistory($state);
    }
}
