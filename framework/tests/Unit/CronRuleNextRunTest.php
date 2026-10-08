<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Daemon\Cron\CronRule;
use PHPUnit\Framework\TestCase;

final class CronRuleNextRunTest extends TestCase
{
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
        $previousZone = date_default_timezone_get();
        date_default_timezone_set('UTC');

        try {
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
        } finally {
            date_default_timezone_set($previousZone);
        }
    }

    public function testMonthDayAndWeekdayMustAllMatch(): void
    {
        $previousZone = date_default_timezone_get();
        date_default_timezone_set('UTC');

        try {
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
        } finally {
            date_default_timezone_set($previousZone);
        }
    }

    public function testSkipsAClockMinuteMissingInTheSpringTransition(): void
    {
        $previousZone = date_default_timezone_get();
        date_default_timezone_set('Europe/Warsaw');

        try {
            $this->assertSame(
                strtotime('2026-03-30 02:30:00 Europe/Warsaw'),
                CronRule::nextRunAfter('30 2 * * *', strtotime('2026-03-29 01:59:00 Europe/Warsaw')),
            );
        } finally {
            date_default_timezone_set($previousZone);
        }
    }

    public function testFindsTheEarliestMinuteInTheRepeatedAutumnHour(): void
    {
        $previousZone = date_default_timezone_get();
        date_default_timezone_set('Europe/Warsaw');

        try {
            $this->assertSame(
                strtotime('2026-10-25 02:30:00 +02:00'),
                CronRule::nextRunAfter('0,30 2 * * *', strtotime('2026-10-25 02:15:00 +02:00')),
            );
            $this->assertSame(
                strtotime('2026-10-25 02:30:00 +01:00'),
                CronRule::nextRunAfter('30 2 * * *', strtotime('2026-10-25 02:45:00 +02:00')),
            );
        } finally {
            date_default_timezone_set($previousZone);
        }
    }
}
