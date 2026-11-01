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

    protected function currentTimestamp(): int
    {
        return $this->now;
    }
}
