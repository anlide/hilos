<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Verification;

use Hilos\Auth\Verification\VerificationSendOutcome;
use Hilos\Constants\TimeConstants;
use Hilos\Utils\Helpers\TimeHelper;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the send-outcome value object (HIL-421).
 *
 * Locks the three shapes apart, because the callers branch on the flags rather
 * than on the seconds: a cooldown hold and a cap refusal both sent nothing, and
 * only the cap flag tells the surface to say no out loud instead of counting down.
 */
final class VerificationSendOutcomeTest extends TestCase
{
    private const int COOLDOWN_SECONDS = 60;

    /** How far the computed moment may sit from the one this case expects, in ms. */
    private const int MOMENT_DELTA_MS = 1000;

    public function testSentReportsADeliveredCodeWithItsCooldown(): void
    {
        $outcome = VerificationSendOutcome::sent(self::COOLDOWN_SECONDS);

        self::assertTrue($outcome->sent);
        self::assertFalse($outcome->capReached);
        self::assertSame(self::COOLDOWN_SECONDS, $outcome->resendInSeconds);
    }

    public function testHeldByCooldownSentNothingButStillCountsDown(): void
    {
        $outcome = VerificationSendOutcome::heldByCooldown(42);

        self::assertFalse($outcome->sent);
        self::assertFalse($outcome->capReached);
        self::assertSame(42, $outcome->resendInSeconds);
    }

    public function testCapReachedSentNothingAndPromisesNoCountdown(): void
    {
        $outcome = VerificationSendOutcome::capReached();

        self::assertFalse($outcome->sent);
        self::assertTrue($outcome->capReached);
        self::assertSame(0, $outcome->resendInSeconds);
    }

    public function testResendAtTurnsTheCooldownIntoAServerMoment(): void
    {
        // What the browser is told (HIL-486): a duration cannot survive a reload,
        // because nothing on the page recorded when the counting began.
        $outcome = VerificationSendOutcome::sent(self::COOLDOWN_SECONDS);

        self::assertEqualsWithDelta(
            TimeHelper::nowMs() + self::COOLDOWN_SECONDS * TimeConstants::MS_PER_SECOND,
            $outcome->resendAt(),
            self::MOMENT_DELTA_MS,
        );
    }

    public function testResendAtOfACapRefusalIsNowRatherThanAPromise(): void
    {
        // A cap carries no seconds, so its moment is simply the present one: the
        // surface never draws it, because the refusal answers with no moment at all.
        self::assertEqualsWithDelta(
            TimeHelper::nowMs(),
            VerificationSendOutcome::capReached()->resendAt(),
            self::MOMENT_DELTA_MS,
        );
    }
}
