<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\EnvConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogStub;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * Cron expressions run on the project clock while the process clock stays UTC.
 *
 * The project zone is set through the real accessor, never by moving the process zone: the
 * daemon's own zone is UTC, so a test that moved it would prove a contract no process has.
 */
final class CronRuleNextRunTest extends TestCase
{
    private const string WARSAW = 'Europe/Warsaw';

    private ?EnvAccessor $previousEnv;

    private string|false $previousProjectZone;

    private string $previousProcessZone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousEnv = Hilos::$env;
        $this->previousProjectZone = getenv(EnvConstants::HILOS_PROJECT_TIME_ZONE->name);
        $this->previousProcessZone = date_default_timezone_get();
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
        putenv(EnvConstants::HILOS_PROJECT_TIME_ZONE->name);
        date_default_timezone_set(TimeConstants::UTC_TIME_ZONE);
    }

    protected function tearDown(): void
    {
        if ($this->previousProjectZone === false) {
            putenv(EnvConstants::HILOS_PROJECT_TIME_ZONE->name);
        } else {
            putenv(EnvConstants::HILOS_PROJECT_TIME_ZONE->name . '=' . $this->previousProjectZone);
        }
        date_default_timezone_set($this->previousProcessZone);
        Hilos::$env = $this->previousEnv;
        parent::tearDown();
    }

    public function testLastFiredAtRecordsARealMatchAndNotTheCreationMinute(): void
    {
        $rule = new CronRule('every-minute', '* * * * *');
        $this->assertNull($rule->lastFiredAt);

        $rule->lastRun = 0.0;
        $before = time();
        $this->assertTrue($rule->shouldRun());
        $after = time();

        $this->assertGreaterThanOrEqual($before, $rule->lastFiredAt);
        $this->assertLessThanOrEqual($after, $rule->lastFiredAt);
        $this->assertFalse($rule->shouldRun());
        $this->assertGreaterThanOrEqual($before, $rule->lastFiredAt);
    }

    public function testNextRunUsesTheFirstMinuteStrictlyAfterTheInstant(): void
    {
        $this->assertSame(
            strtotime('2026-10-08 10:15:00 UTC'),
            CronRule::nextRunAfter('*/15 * * * *', strtotime('2026-10-08 10:14:59 UTC')),
        );
        $this->assertSame(
            strtotime('2026-10-08 10:30:00 UTC'),
            CronRule::nextRunAfter('*/15 * * * *', strtotime('2026-10-08 10:15:00 UTC')),
        );
        $this->assertSame(
            strtotime('2026-10-09 03:00:00 UTC'),
            CronRule::nextRunAfter('0 3 * * *', strtotime('2026-10-08 03:00:00 UTC')),
        );
    }

    public function testMonthDayAndWeekdayMustAllMatch(): void
    {
        // 1 February 2027 is Monday; 1 January 2027 is Friday.
        $this->assertSame(
            strtotime('2027-02-01 00:00:00 UTC'),
            CronRule::nextRunAfter('0 0 1 * 1', strtotime('2027-01-01 00:00:00 UTC')),
        );
        $this->assertSame(
            strtotime('2028-02-29 00:00:00 UTC'),
            CronRule::nextRunAfter('0 0 29 2 *', strtotime('2027-03-01 00:00:00 UTC')),
        );
        $this->assertSame(
            strtotime('2044-02-29 00:00:00 UTC'),
            CronRule::nextRunAfter('0 0 29 2 1', strtotime('2028-02-29 00:00:00 UTC')),
        );
        $this->assertNull(CronRule::nextRunAfter('0 0 31 2 *', strtotime('2026-01-01 UTC')));
        $this->assertNull(CronRule::nextRunAfter('bad expression', strtotime('2026-01-01 UTC')));
    }

    public function testAMissingProjectZoneIsUtcWhateverTheProcessZone(): void
    {
        date_default_timezone_set(self::WARSAW);

        $this->assertSame(
            strtotime('2026-01-15 03:00:00 UTC'),
            CronRule::nextRunAfter('0 3 * * *', strtotime('2026-01-15 00:00:00 UTC')),
        );
        $this->assertSame(
            strtotime('2026-07-15 03:00:00 UTC'),
            CronRule::nextRunAfter('0 3 * * *', strtotime('2026-07-15 00:00:00 UTC')),
        );

        $rule = $this->ruleAt('0 3 * * *', '2026-01-15 02:00:00 UTC');
        $this->assertFalse($rule->shouldRun());
        $rule->now = strtotime('2026-01-15 03:00:00 UTC');
        $this->assertTrue($rule->shouldRun());

        $this->assertSame(self::WARSAW, date_default_timezone_get());
    }

    public function testADailyRunFollowsTheProjectClockInWinterAndSummer(): void
    {
        putenv(EnvConstants::HILOS_PROJECT_TIME_ZONE->name . '=' . self::WARSAW);

        $this->assertSame(
            strtotime('2026-01-15 02:00:00 UTC'),
            CronRule::nextRunAfter('0 3 * * *', strtotime('2026-01-15 00:00:00 UTC')),
        );
        $this->assertSame(
            strtotime('2026-07-15 01:00:00 UTC'),
            CronRule::nextRunAfter('0 3 * * *', strtotime('2026-07-15 00:00:00 UTC')),
        );

        $winter = $this->ruleAt('0 3 * * *', '2026-01-15 03:00:00 UTC');
        $this->assertFalse($winter->shouldRun());
        $winter->now = strtotime('2026-01-15 02:00:00 UTC');
        $this->assertTrue($winter->shouldRun());

        $summer = $this->ruleAt('0 3 * * *', '2026-07-15 02:00:00 UTC');
        $this->assertFalse($summer->shouldRun());
        $summer->now = strtotime('2026-07-15 01:00:00 UTC');
        $this->assertTrue($summer->shouldRun());

        $this->assertSame(TimeConstants::UTC_TIME_ZONE, date_default_timezone_get());
    }

    public function testSkipsAClockMinuteMissingInTheSpringTransition(): void
    {
        putenv(EnvConstants::HILOS_PROJECT_TIME_ZONE->name . '=' . self::WARSAW);

        // 29 March 2026 has no 02:30 in Warsaw: the clock goes from 01:59:59 CET to 03:00 CEST.
        $this->assertSame(
            strtotime('2026-03-30 00:30:00 UTC'),
            CronRule::nextRunAfter('30 2 * * *', strtotime('2026-03-28 23:59:00 UTC')),
        );

        $rule = $this->ruleAt('30 2 * * *', '2026-03-29 00:30:00 UTC');
        $this->assertFalse($rule->shouldRun());
        $rule->now = strtotime('2026-03-29 01:30:00 UTC');
        $this->assertFalse($rule->shouldRun());
        $rule->now = strtotime('2026-03-30 00:30:00 UTC');
        $this->assertTrue($rule->shouldRun());

        $this->assertSame(TimeConstants::UTC_TIME_ZONE, date_default_timezone_get());
    }

    public function testFindsTheEarliestMinuteInTheRepeatedAutumnHour(): void
    {
        putenv(EnvConstants::HILOS_PROJECT_TIME_ZONE->name . '=' . self::WARSAW);

        $this->assertSame(
            strtotime('2026-10-25 02:30:00 +02:00'),
            CronRule::nextRunAfter('0,30 2 * * *', strtotime('2026-10-25 02:15:00 +02:00')),
        );
        $this->assertSame(
            strtotime('2026-10-25 02:30:00 +01:00'),
            CronRule::nextRunAfter('30 2 * * *', strtotime('2026-10-25 02:45:00 +02:00')),
        );
    }

    public function testEachAbsoluteMinuteOfTheRepeatedAutumnHourFiresOnce(): void
    {
        putenv(EnvConstants::HILOS_PROJECT_TIME_ZONE->name . '=' . self::WARSAW);
        $first = strtotime('2026-10-25 00:30:00 UTC');
        $second = strtotime('2026-10-25 01:30:00 UTC');

        // 02:30 happens twice on 25 October 2026 in Warsaw: at 02:30 CEST and again at 02:30 CET.
        $this->assertSame($first, CronRule::nextRunAfter('30 2 * * *', strtotime('2026-10-25 00:00:00 UTC')));
        $this->assertSame($second, CronRule::nextRunAfter('30 2 * * *', $first));

        $rule = $this->ruleAt('30 2 * * *', '2026-10-25 00:30:00 UTC');
        $this->assertTrue($rule->shouldRun());
        $this->assertSame($first, $rule->lastFiredAt);
        $rule->now = strtotime('2026-10-25 00:30:40 UTC');
        $this->assertFalse($rule->shouldRun());
        $this->assertSame($first, $rule->lastFiredAt);

        $rule->now = $second;
        $this->assertTrue($rule->shouldRun());
        $this->assertSame($second, $rule->lastFiredAt);
        $rule->now = strtotime('2026-10-25 01:30:20 UTC');
        $this->assertFalse($rule->shouldRun());
        $this->assertSame($second, $rule->lastFiredAt);

        $this->assertSame(TimeConstants::UTC_TIME_ZONE, date_default_timezone_get());
    }

    /**
     * @param string $expression Cron expression of the rule
     * @param string $moment The rule's first "now", a time string naming its own zone
     * @return FixedClockCronRule Rule whose seeded minute is far in the past
     */
    private function ruleAt(string $expression, string $moment): FixedClockCronRule
    {
        $rule = new FixedClockCronRule('fixed-clock', $expression);
        // The constructor seeds the real current minute; wind it back so the first check counts.
        $rule->lastRun = 0.0;
        $rule->now = strtotime($moment);

        return $rule;
    }
}

/** Cron rule whose "now" is the test's to set, so shouldRun() is checked at a fixed moment. */
final class FixedClockCronRule extends CronRule
{
    public int $now = 0;

    protected function currentTimestamp(): int
    {
        return $this->now;
    }
}
