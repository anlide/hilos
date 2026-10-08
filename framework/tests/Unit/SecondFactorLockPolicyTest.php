<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Auth\SecondFactor\SecondFactorLockPolicy;
use Hilos\Constants\EnvConstants;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogStub;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * The numbers behind the ceiling on wrong app codes (HIL-1285): the ladder a guesser walks up
 * lock after lock, the day without a lock that walks it back down, and how the time left reads.
 *
 * In production the steps are a quarter of an hour to a day apart and the cool-down is a day;
 * nothing that runs in seconds would ever see them, so they are pinned here on the arithmetic.
 */
final class SecondFactorLockPolicyTest extends TestCase
{
    /** @var list<int> Ladder these cases judge against, short enough to reach the end of */
    private const array STEPS = [60, 600, 3600];

    /** A moment the cases count from (Unix seconds). */
    private const int NOW = 1_800_000_000;

    /** A day, as the cool-down of the ladder is. */
    private const int DAY = 86400;

    /** @var list<string> Environment names this case sets and has to unset again */
    private const array KNOBS = [
        'HILOS_SECOND_FACTOR_LOCK_MISSES',
        'HILOS_SECOND_FACTOR_LOCK_STEPS',
    ];

    private ?EnvAccessor $previousEnv = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousEnv = Hilos::$env;
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
    }

    protected function tearDown(): void
    {
        foreach (self::KNOBS as $knob) {
            putenv($knob);
        }

        Hilos::$env = $this->previousEnv;

        parent::tearDown();
    }

    public function testTheDefaultsAreTenMissesAndAQuarterOfAnHourToADay(): void
    {
        $policy = SecondFactorLockPolicy::fromEnv();

        $this->assertSame(10, $policy->misses());
        $this->assertSame(self::DAY, $policy->windowSeconds());
        $this->assertSame([900, 3600, 21600, 86400], array_map($policy->lockSecondsFor(...), [1, 2, 3, 4]));
    }

    public function testNumbersThatWouldNeverLockOrLockAtOnceFallBackToTheDefaults(): void
    {
        putenv(EnvConstants::HILOS_SECOND_FACTOR_LOCK_MISSES->name . '=0');
        putenv(EnvConstants::HILOS_SECOND_FACTOR_LOCK_STEPS->name . '=nonsense,0,-1');

        $policy = SecondFactorLockPolicy::fromEnv();

        $this->assertSame(10, $policy->misses());
        $this->assertSame(900, $policy->lockSecondsFor(1));
        $this->assertSame(86400, $policy->lockSecondsFor(4));
    }

    public function testANegativeCeilingFallsBackToTheDefaultToo(): void
    {
        putenv(EnvConstants::HILOS_SECOND_FACTOR_LOCK_MISSES->name . '=-3');

        $this->assertSame(10, SecondFactorLockPolicy::fromEnv()->misses());
    }

    public function testEachLockWithinADayOfTheLastHoldsLongerThanTheLastOne(): void
    {
        $policy = $this->policy();

        $step = 0;
        $until = null;
        $now = self::NOW;
        $locks = [];
        foreach (self::STEPS as $ignored) {
            $step = $policy->nextStep($step, $until, $now);
            $locks[] = $policy->lockSecondsFor($step);
            $until = $now + $policy->lockSecondsFor($step);
            // The next ten misses come an hour after this lock ends: well within a day.
            $now = $until + 3600;
        }

        $this->assertSame(self::STEPS, $locks);
    }

    public function testTheLadderRepeatsItsLastStepAndTheStepStaysOnIt(): void
    {
        $policy = $this->policy();
        $last = count(self::STEPS);

        // However many locks come within a day of each other, the step the row keeps stays on the ladder.
        $step = $last;
        for ($lock = 0; $lock < 300; $lock++) {
            $step = $policy->nextStep($step, self::NOW, self::NOW + 60);
        }
        $this->assertSame($last, $step);
        $this->assertSame(self::STEPS[$last - 1], $policy->lockSecondsFor($step));
        $this->assertSame(self::STEPS[$last - 1], $policy->lockSecondsFor($last + 5), 'A step kept from a longer ladder takes the last');
    }

    public function testADayWithoutALockStartsTheLadderAgain(): void
    {
        $policy = $this->policy();

        $this->assertSame(3, $policy->nextStep(2, self::NOW, self::NOW + self::DAY - 1));
        $this->assertSame(1, $policy->nextStep(2, self::NOW, self::NOW + self::DAY));
    }

    public function testAPersonNeverLockedStartsOnTheFirstStep(): void
    {
        $policy = $this->policy();

        $this->assertSame(1, $policy->nextStep(0, null, self::NOW));
        $this->assertSame(1, $policy->nextStep(0, self::NOW, self::NOW));
    }

    public function testTheTimeLeftReadsInMinutesUnderAnHourAndInHoursFromOneUp(): void
    {
        $policy = $this->policy();

        $this->assertSame('15 min', $policy->waitText(900));
        $this->assertSame('15 min', $policy->waitText(841));
        $this->assertSame('1 min', $policy->waitText(1));
        $this->assertSame('1 min', $policy->waitText(0));
        $this->assertSame('60 min', $policy->waitText(3599));
        $this->assertSame('1 h', $policy->waitText(3600));
        $this->assertSame('2 h', $policy->waitText(3601));
        $this->assertSame('24 h', $policy->waitText(self::DAY));
    }

    /**
     * The policy these cases judge against.
     *
     * @return SecondFactorLockPolicy Policy built from the numbers set here
     */
    private function policy(): SecondFactorLockPolicy
    {
        putenv(EnvConstants::HILOS_SECOND_FACTOR_LOCK_MISSES->name . '=3');
        putenv(EnvConstants::HILOS_SECOND_FACTOR_LOCK_STEPS->name . '=' . implode(',', self::STEPS));

        return SecondFactorLockPolicy::fromEnv();
    }
}
