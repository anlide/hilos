<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\EnvConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Core\Daemon\Cron\Exception\CronException;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogStub;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * An armed rule fires at its next check, once, and its schedule stays as it was.
 *
 * The check is placed at a fixed moment through the currentTimestamp() seam, on a project clock
 * left unset and therefore UTC, as in CronRuleNextRunTest.
 */
final class CronRuleFireOnNextCheckTest extends TestCase
{
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

    public function testAnArmedRuleFiresOnceWhenItsExpressionDoesNotMatch(): void
    {
        $rule = $this->ruleAt('0 3 * * *', '2026-10-08 10:14:30 UTC');
        $this->assertFalse($rule->shouldRun());
        $this->assertNull($rule->lastFiredAt);

        $rule->fireOnNextCheck();
        $this->assertTrue($rule->shouldRun());
        $this->assertSame(1_791_454_470, $rule->lastFiredAt);
        $this->assertSame(29_857_574.0, $rule->lastRun);

        $rule->now = strtotime('2026-10-08 10:14:50 UTC');
        $this->assertFalse($rule->shouldRun());
        $rule->now = strtotime('2026-10-08 10:15:30 UTC');
        $this->assertFalse($rule->shouldRun());
        $this->assertSame(1_791_454_470, $rule->lastFiredAt);
        $this->assertSame(29_857_574.0, $rule->lastRun);
    }

    public function testAnArmedRuleFiresInAMinuteItsScheduleAlreadyRan(): void
    {
        $rule = $this->ruleAt('*/15 * * * *', '2026-10-08 10:15:00 UTC');
        $this->assertTrue($rule->shouldRun());
        $this->assertSame(1_791_454_500, $rule->lastFiredAt);
        $rule->now = strtotime('2026-10-08 10:15:40 UTC');
        $this->assertFalse($rule->shouldRun());

        $rule->fireOnNextCheck();
        $this->assertTrue($rule->shouldRun());
        $this->assertSame(1_791_454_540, $rule->lastFiredAt);
        $this->assertSame(29_857_575.0, $rule->lastRun);

        $rule->now = strtotime('2026-10-08 10:15:50 UTC');
        $this->assertFalse($rule->shouldRun());
        $this->assertSame(1_791_454_540, $rule->lastFiredAt);
    }

    public function testArmingLeavesTheScheduleAsItWas(): void
    {
        $rule = $this->ruleAt('0 3 * * *', '2026-10-08 10:14:30 UTC');
        $this->assertSame(
            strtotime('2026-10-09 03:00:00 UTC'),
            CronRule::nextRunAfter($rule->expression, strtotime('2026-10-08 10:14:30 UTC')),
        );

        $rule->fireOnNextCheck();
        $this->assertTrue($rule->shouldRun());
        $this->assertSame('0 3 * * *', $rule->expression);
        $this->assertSame(
            strtotime('2026-10-09 03:00:00 UTC'),
            CronRule::nextRunAfter($rule->expression, strtotime('2026-10-08 10:14:30 UTC')),
        );

        $rule->now = strtotime('2026-10-09 03:00:10 UTC');
        $this->assertTrue($rule->shouldRun());
        $this->assertSame(strtotime('2026-10-09 03:00:10 UTC'), $rule->lastFiredAt);
    }

    /** A matching minute stays unmarked while the pause belongs only to this rule instance. */
    public function testAPauseSuppressesTheScheduleWithoutReadingTimeOrMarkingARun(): void
    {
        $rule = $this->ruleAt('*/15 * * * *', '2026-10-08 10:15:00 UTC');
        $rule->pauseSchedule('pause-a');

        $this->assertFalse($rule->shouldRun());
        $this->assertSame(0.0, $rule->lastRun);
        $this->assertNull($rule->lastFiredAt);
        $this->assertSame(0, $rule->clockReads);
        $this->assertSame(0, $rule->matchChecks);
        $this->assertFalse($rule->isArmedForNextCheck());
        $this->assertSame('*/15 * * * *', $rule->expression);
        $this->assertSame(
            strtotime('2026-10-08 10:30:00 UTC'),
            CronRule::nextRunAfter($rule->expression, $rule->now),
        );
        $this->assertTrue($this->ruleAt('*/15 * * * *', '2026-10-08 10:15:00 UTC')->shouldRun());
    }

    /** Arming wins once; the pause still suppresses later matching minutes. */
    public function testAnArmedPausedRuleFiresExactlyOnce(): void
    {
        $rule = $this->ruleAt('*/15 * * * *', '2026-10-08 10:15:00 UTC');
        $rule->pauseSchedule('pause-a');
        $rule->fireOnNextCheck();

        $this->assertTrue($rule->isArmedForNextCheck());
        $this->assertTrue($rule->shouldRun());
        $this->assertFalse($rule->isArmedForNextCheck());
        $this->assertSame(1_791_454_500, $rule->lastFiredAt);
        $this->assertSame(29_857_575.0, $rule->lastRun);
        $this->assertFalse($rule->shouldRun());

        $rule->now = strtotime('2026-10-08 10:30:00 UTC');
        $this->assertFalse($rule->shouldRun());
        $this->assertSame(1_791_454_500, $rule->lastFiredAt);
        $this->assertSame(29_857_575.0, $rule->lastRun);
        $this->assertSame(1, $rule->clockReads);
        $this->assertSame(0, $rule->matchChecks);
    }

    /** Removing one key, again or unknown, cannot remove another caller's pause. */
    public function testIndependentPauseKeysMustEachBeRemoved(): void
    {
        $rule = $this->ruleAt('*/15 * * * *', '2026-10-08 10:15:00 UTC');
        $rule->pauseSchedule('pause-a');
        $rule->pauseSchedule('pause-b');
        $rule->pauseSchedule('pause-a');
        $rule->resumeSchedule('pause-a');
        $this->assertFalse($rule->shouldRun());

        foreach (['pause-a', 'unknown-pause'] as $pauseId) {
            try {
                $rule->resumeSchedule($pauseId);
                $this->fail('Removing an absent pause must be refused.');
            } catch (CronException) {
                $this->assertFalse($rule->shouldRun());
            }
        }

        $this->assertSame(0.0, $rule->lastRun);
        $this->assertNull($rule->lastFiredAt);
        $rule->resumeSchedule('pause-b');
        $this->assertTrue($rule->shouldRun());
    }

    /** The last removal preserves a cached miss and lets the next matching minute run normally. */
    public function testRemovingTheLastPauseRestoresOrdinaryScheduledChecks(): void
    {
        $rule = $this->ruleAt('*/15 * * * *', '2026-10-08 10:14:30 UTC');
        $this->assertFalse($rule->shouldRun());
        $this->assertSame(1, $rule->matchChecks);
        $rule->pauseSchedule('pause-a');
        $this->assertFalse($rule->shouldRun());
        $this->assertSame(1, $rule->clockReads);
        $rule->resumeSchedule('pause-a');

        $this->assertSame(0.0, $rule->lastRun);
        $this->assertNull($rule->lastFiredAt);
        $this->assertFalse($rule->isArmedForNextCheck());
        $this->assertFalse($rule->shouldRun());
        $this->assertSame(1, $rule->matchChecks);

        $rule->now = strtotime('2026-10-08 10:15:00 UTC');
        $this->assertTrue($rule->shouldRun());
        $this->assertSame(1_791_454_500, $rule->lastFiredAt);
        $this->assertSame(29_857_575.0, $rule->lastRun);
        $this->assertFalse($rule->shouldRun());
    }

    /**
     * @param string $expression Cron expression of the rule
     * @param string $moment The rule's first "now", a time string naming its own zone
     * @return FireOnNextCheckClockRule Rule whose seeded minute is far in the past
     */
    private function ruleAt(string $expression, string $moment): FireOnNextCheckClockRule
    {
        $rule = new FireOnNextCheckClockRule('fixed-clock', $expression);
        // The constructor seeds the real current minute; wind it back so the first check counts.
        $rule->lastRun = 0.0;
        $rule->now = strtotime($moment);

        return $rule;
    }
}

/** Cron rule whose "now" is the test's to set, so an armed check is placed at a fixed moment. */
final class FireOnNextCheckClockRule extends CronRule
{
    public int $now = 0;

    public int $clockReads = 0;

    public int $matchChecks = 0;

    /** @return int Fixed moment, counting checks that reach the clock */
    protected function currentTimestamp(): int
    {
        $this->clockReads++;
        return $this->now;
    }

    /**
     * @param int $timestamp Fixed check moment
     * @return bool Actual expression match, counting calculations that bypass the cache
     */
    protected function matchesAt(int $timestamp): bool
    {
        $this->matchChecks++;
        return parent::matchesAt($timestamp);
    }
}
