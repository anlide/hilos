<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\SecondFactor;

use Hilos\Auth\SecondFactor\SecondFactorPolicy;
use Hilos\Auth\SecondFactor\SecondFactorResetWait;
use Hilos\Auth\SecondFactor\SecondFactorSettings;
use Hilos\Constants\TimeConstants;
use PHPUnit\Framework\TestCase;

/**
 * When a parked shorter removal wait takes over, and that the parked moment keeps its milliseconds.
 *
 * The choice and the moment are passed in. Nothing here reads storage.
 */
final class SecondFactorResetWaitTest extends TestCase
{
    /** A moment the cases count from (epoch milliseconds). */
    private const int NOW_MS = 1_800_000_000_000;

    /** The same moment with a remainder a whole second would drop. */
    private const int NOW_WITH_REMAINDER_MS = 1_800_000_000_123;

    /**
     * Before the parked moment the wait in force stays; on that moment and after, the parked wait is in force.
     */
    public function testTheCurrentWaitHoldsUntilTheParkedMoment(): void
    {
        $policy = $this->policy(8);

        $before = new SecondFactorResetWait(20, 5, self::NOW_MS + 1);
        $onTheMoment = new SecondFactorResetWait(20, 5, self::NOW_MS);
        $after = new SecondFactorResetWait(20, 5, self::NOW_MS - 1);

        self::assertSame(20, $before->effectiveDays($policy, self::NOW_MS));
        self::assertSame(5, $onTheMoment->effectiveDays($policy, self::NOW_MS));
        self::assertSame(5, $after->effectiveDays($policy, self::NOW_MS));
    }

    /**
     * A shorter choice waits until the wait in force now would have run out.
     */
    public function testAShorterWaitIsParkedUntilTheCurrentOneRunsOut(): void
    {
        $policy = $this->policy(8);
        $parked = new SecondFactorResetWait(20, null, null)->withRequested(5, $policy, self::NOW_MS);

        self::assertSame(20, $parked->days);
        self::assertSame(5, $parked->pendingDays);
        self::assertSame(
            self::NOW_MS + 20 * TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND,
            $parked->pendingFrom,
        );
    }

    /**
     * A longer choice applies at once and drops whatever was parked.
     */
    public function testALongerWaitAppliesAtOnceAndDropsTheParkedOne(): void
    {
        $policy = $this->policy(8);
        $applied = new SecondFactorResetWait(
            10,
            4,
            self::NOW_MS + TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND,
        )->withRequested(18, $policy, self::NOW_MS);

        self::assertSame(18, $applied->days);
        self::assertNull($applied->pendingDays);
        self::assertNull($applied->pendingFrom);
    }

    /**
     * No choice and no parked wait take the policy default, and a parked moment keeps milliseconds of now.
     */
    public function testAnUnsetWaitTakesThePolicyDefaultAndKeepsParkedMilliseconds(): void
    {
        $policy = $this->policy(14);
        $unset = new SecondFactorResetWait(null, null, null);
        $parked = $unset->withRequested(3, $policy, self::NOW_WITH_REMAINDER_MS);

        self::assertSame(14, $unset->effectiveDays($policy, self::NOW_WITH_REMAINDER_MS));
        self::assertSame(14, $parked->days);
        self::assertSame(3, $parked->pendingDays);
        self::assertSame(
            self::NOW_WITH_REMAINDER_MS + 14 * TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND,
            $parked->pendingFrom,
        );
    }

    /**
     * Bounds these cases judge against.
     *
     * @param int $defaultDays Removal wait a person starts on
     * @return SecondFactorPolicy Policy that never reads storage
     */
    private function policy(int $defaultDays): SecondFactorPolicy
    {
        return new SecondFactorPolicy(
            enabled: false,
            forgetTrustsOnDisable: false,
            required: SecondFactorSettings::REQUIRED_NONE,
            trustDays: 0,
            backupCodes: 10,
            resetWaitDefaultDays: $defaultDays,
            resetWaitMinDays: 1,
            resetWaitMaxDays: 30,
        );
    }
}
