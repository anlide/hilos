<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Verification;

use Hilos\Auth\Verification\VerificationSendOutcome;
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
}
