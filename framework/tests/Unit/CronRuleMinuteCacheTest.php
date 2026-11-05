<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use DateInvalidTimeZoneException;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogStub;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/** Negative minute checks are local memory, separate from actual cron runs. */
final class CronRuleMinuteCacheTest extends TestCase
{
    private ?EnvAccessor $previousEnv;

    private string|false $previousProjectZone;

    /** Install an isolated env accessor with the default project zone. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousEnv = Hilos::$env;
        $this->previousProjectZone = getenv(EnvConstants::HILOS_PROJECT_TIME_ZONE->name);
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
        putenv(EnvConstants::HILOS_PROJECT_TIME_ZONE->name);
    }

    /** Restore the accessor and environment value after every case. */
    protected function tearDown(): void
    {
        if ($this->previousProjectZone === false) {
            putenv(EnvConstants::HILOS_PROJECT_TIME_ZONE->name);
        } else {
            putenv(EnvConstants::HILOS_PROJECT_TIME_ZONE->name . '=' . $this->previousProjectZone);
        }
        Hilos::$env = $this->previousEnv;
        parent::tearDown();
    }

    /** A stable negative minute computes once and leaves run history alone. */
    public function testStableNegativeMinuteIsCalculatedOnce(): void
    {
        $rule = $this->ruleAt('0 3 * * *');
        $this->assertFalse($rule->shouldRun());
        $rule->now = strtotime('2026-10-08 10:14:59 UTC');
        $this->assertFalse($rule->shouldRun());
        $this->assertFalse($rule->shouldRun());
        $this->assertSame(1, $rule->calculations);
        $this->assertSame(0.0, $rule->lastRun);
        $this->assertNull($rule->lastFiredAt);
    }

    /** Moving the absolute minute in either direction invalidates a negative check. */
    public function testMinuteChangesRecalculateInBothDirections(): void
    {
        $rule = $this->ruleAt('0 3 * * *');
        $this->assertFalse($rule->shouldRun());
        $rule->now = strtotime('2026-10-08 10:15:00 UTC');
        $this->assertFalse($rule->shouldRun());
        $this->assertSame(2, $rule->calculations);
        $rule->now = strtotime('2026-10-08 10:14:00 UTC');
        $this->assertFalse($rule->shouldRun());
        $this->assertFalse($rule->shouldRun());
        $this->assertSame(3, $rule->calculations);
    }

    /** An edited expression can become due immediately after a cached miss. */
    public function testExpressionChangeRecalculatesInTheSameMinute(): void
    {
        $rule = $this->ruleAt('0 3 * * *');
        $this->assertFalse($rule->shouldRun());
        $rule->expression = '14 10 * * *';
        $this->assertTrue($rule->shouldRun());
        $this->assertSame(2, $rule->calculations);
        $this->assertSame($rule->now, $rule->lastFiredAt);
        $this->assertFalse($rule->shouldRun());
        $this->assertSame(2, $rule->calculations);
    }

    /** Replacing env recalculates even when the replacement gives the same zone. */
    public function testEnvIdentityChangeRecalculatesWithTheCurrentProjectZone(): void
    {
        $rule = $this->ruleAt('14 12 * * *');
        $this->assertFalse($rule->shouldRun());
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
        $this->assertFalse($rule->shouldRun());
        $this->assertSame(2, $rule->calculations);

        putenv(EnvConstants::HILOS_PROJECT_TIME_ZONE->name . '=Europe/Warsaw');
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
        $this->assertTrue($rule->shouldRun());
        $this->assertSame(3, $rule->calculations);
        $this->assertSame($rule->now, $rule->lastFiredAt);
    }

    /** A forced run bypasses the cache and still blocks a scheduled run in its minute. */
    public function testForcedFireBypassesNegativeCacheWithoutDoubleFire(): void
    {
        $rule = $this->ruleAt('0 3 * * *');
        $this->assertFalse($rule->shouldRun());
        $rule->fireOnNextCheck();
        $this->assertTrue($rule->shouldRun());
        $this->assertSame($rule->now, $rule->lastFiredAt);
        $this->assertSame(29_857_574.0, $rule->lastRun);
        $this->assertFalse($rule->isArmedForNextCheck());

        $rule->expression = '* * * * *';
        $this->assertFalse($rule->shouldRun());
        $this->assertSame(1, $rule->calculations);
        $rule->now = strtotime('2026-10-08 10:15:00 UTC');
        $this->assertTrue($rule->shouldRun());
        $this->assertSame(2, $rule->calculations);
    }

    /** A calendar failure must be retried, rather than remembered as a negative match. */
    public function testCalculationExceptionIsRetriedInTheSameMinute(): void
    {
        $rule = $this->ruleAt('0 3 * * *');
        putenv(EnvConstants::HILOS_PROJECT_TIME_ZONE->name . '=Invalid/Zone');
        try {
            $rule->shouldRun();
            $this->fail('An invalid project zone must refuse the calculation.');
        } catch (DateInvalidTimeZoneException) {
            $this->assertSame(1, $rule->calculations);
            $this->assertSame(0.0, $rule->lastRun);
            $this->assertNull($rule->lastFiredAt);
        }

        putenv(EnvConstants::HILOS_PROJECT_TIME_ZONE->name);
        $this->assertFalse($rule->shouldRun());
        $this->assertFalse($rule->shouldRun());
        $this->assertSame(2, $rule->calculations);
    }

    /** A scheduled match replaces the previous check even when the clock later moves back. */
    public function testSuccessfulCalculationDiscardsThePreviousNegativeMinute(): void
    {
        $rule = $this->ruleAt('15 10 * * *');
        $this->assertFalse($rule->shouldRun());
        $rule->now = strtotime('2026-10-08 10:15:00 UTC');
        $this->assertTrue($rule->shouldRun());
        $rule->now = strtotime('2026-10-08 10:14:00 UTC');
        $this->assertFalse($rule->shouldRun());
        $this->assertSame(3, $rule->calculations);
    }

    /** Creation-minute suppression takes precedence over schedule calculation. */
    public function testSeededMinuteStillSkipsCalculation(): void
    {
        $rule = $this->ruleAt('* * * * *');
        $rule->lastRun = 29_857_574.0;
        $this->assertFalse($rule->shouldRun());
        $this->assertSame(0, $rule->calculations);
        $this->assertNull($rule->lastFiredAt);
    }

    /**
     * @param string $expression Schedule under test
     * @return MinuteCacheClockRule Rule with a fixed clock and no previous run
     */
    private function ruleAt(string $expression): MinuteCacheClockRule
    {
        $rule = new MinuteCacheClockRule('minute-cache', $expression);
        $rule->lastRun = 0.0;
        $rule->now = strtotime('2026-10-08 10:14:30 UTC');
        return $rule;
    }
}

/** Counts actual calendar calculations while using the real matching implementation. */
final class MinuteCacheClockRule extends CronRule
{
    public int $now = 0;

    public int $calculations = 0;

    /** @return int Fixed Unix time of this check */
    protected function currentTimestamp(): int
    {
        return $this->now;
    }

    /**
     * @param int $timestamp Unix time of the check
     * @return bool Actual expression match on the project clock
     */
    protected function matchesAt(int $timestamp): bool
    {
        $this->calculations++;
        return parent::matchesAt($timestamp);
    }
}
