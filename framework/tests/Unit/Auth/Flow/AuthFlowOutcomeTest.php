<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Flow;

use Hilos\Auth\Flow\AuthFlowOutcome;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the wire form of an auth submit's answer (HIL-413 contract).
 *
 * The reply is read by a browser that was given no schema for it, so what it does
 * and does not carry is the contract: an absent slot means "nothing to say about
 * this", and the surface leaves whatever it is showing alone. Locking the omission
 * matters as much as locking the value - a key that appeared as null would read as
 * "there is no countdown" and wipe a live one.
 */
final class AuthFlowOutcomeTest extends TestCase
{
    private const string STEP = 'code';
    private const string INTENT = 'register';
    private const int RESEND_AT = 1_760_000_060_000;
    private const int EXPIRES_AT = 1_760_000_900_000;

    public function testAMoveCarriesBothMomentsToTheSurface(): void
    {
        $wire = AuthFlowOutcome::moveTo(self::STEP, self::INTENT, self::RESEND_AT, self::EXPIRES_AT)->toArray();

        self::assertTrue($wire['ok']);
        self::assertSame(['step' => self::STEP, 'intent' => self::INTENT], $wire['next']);
        self::assertSame(self::RESEND_AT, $wire['resendAt']);
        self::assertSame(self::EXPIRES_AT, $wire['expiresAt']);
    }

    public function testASendThatMovesNowhereStillNamesWhatItSent(): void
    {
        $wire = AuthFlowOutcome::sent(self::RESEND_AT, self::EXPIRES_AT)->toArray();

        self::assertTrue($wire['ok']);
        self::assertArrayNotHasKey('next', $wire);
        self::assertSame(self::EXPIRES_AT, $wire['expiresAt']);
    }

    public function testAnOutcomeWithNothingWaitingOmitsTheMomentsEntirely(): void
    {
        $wire = AuthFlowOutcome::moveTo(self::STEP, self::INTENT)->toArray();

        self::assertArrayNotHasKey('resendAt', $wire);
        self::assertArrayNotHasKey('expiresAt', $wire);
    }

    public function testARefusalCarriesNoMoments(): void
    {
        $wire = AuthFlowOutcome::refuse(AuthFlowOutcome::CODE_SEND_CAP_REACHED, 'Too many')->toArray();

        self::assertFalse($wire['ok']);
        self::assertSame(AuthFlowOutcome::CODE_SEND_CAP_REACHED, $wire['code']);
        self::assertArrayNotHasKey('expiresAt', $wire);
    }

    /**
     * @throws InvalidFormatException When the fixture is not a valid outcome
     */
    public function testTheWireFormRestoresEveryMoment(): void
    {
        $restored = AuthFlowOutcome::fromArray(
            AuthFlowOutcome::moveTo(self::STEP, self::INTENT, self::RESEND_AT, self::EXPIRES_AT)->toArray(),
        );

        self::assertSame(self::RESEND_AT, $restored->resendAt);
        self::assertSame(self::EXPIRES_AT, $restored->expiresAt);
    }
}
