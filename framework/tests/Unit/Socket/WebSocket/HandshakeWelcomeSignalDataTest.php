<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Socket\WebSocket;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Socket\WebSocket\DTO\HandshakeWelcomeSignalData;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the handshake welcome payload (HIL-267 slice 6).
 *
 * Pins the wire shape the master sends behind the 101 response: the build timestamp plus the
 * nested protectedMode block the frontend reads to learn it is locked out by a cluster freeze -
 * the active flag and, since HIL-268, the words to say so. The master's runtime read that fills
 * the block is exercised at e2e in demo/cluster; here we lock the serialized shape so the frame
 * never drifts.
 */
final class HandshakeWelcomeSignalDataTest extends TestCase
{
    public function testWelcomeDefaultsToProtectedModeInactive(): void
    {
        $welcome = new HandshakeWelcomeSignalData(build: 'dev', sessionCookieName: 'hilos_session_token');

        $this->assertSame(
            [
                HandshakeWelcomeSignalData::BUILD => 'dev',
                HandshakeWelcomeSignalData::SESSION_COOKIE_NAME => 'hilos_session_token',
                HandshakeWelcomeSignalData::PROTECTED_MODE => [
                    HandshakeWelcomeSignalData::PROTECTED_MODE_ACTIVE => false,
                    HandshakeWelcomeSignalData::PROTECTED_MODE_OPERATION => null,
                    HandshakeWelcomeSignalData::PROTECTED_MODE_TITLE => null,
                    HandshakeWelcomeSignalData::PROTECTED_MODE_MESSAGE => null,
                    HandshakeWelcomeSignalData::PROTECTED_MODE_ACCEPTS_PASS => false,
                    HandshakeWelcomeSignalData::PROTECTED_MODE_PASS_ISSUED => false,
                ],
            ],
            $welcome->toArray(),
        );
    }

    public function testWelcomeCarriesTheActiveFreezeFlag(): void
    {
        $welcome = new HandshakeWelcomeSignalData(
            build: '20260101',
            sessionCookieName: 'hilos_session_token',
            protectedModeActive: true,
        );

        $restored = HandshakeWelcomeSignalData::fromArray($welcome->toArray());

        $this->assertSame('20260101', $restored->build);
        $this->assertTrue($restored->protectedModeActive);
    }

    public function testWelcomeCarriesTheMaintenanceCopyOfTheRunningOperation(): void
    {
        $welcome = new HandshakeWelcomeSignalData(
            build: '20260101',
            sessionCookieName: 'hilos_session_token',
            protectedModeActive: true,
            protectedModeOperation: 'restore',
            protectedModeTitle: 'Maintenance in progress',
            protectedModeMessage: 'The application is briefly unavailable.',
        );

        $restored = HandshakeWelcomeSignalData::fromArray($welcome->toArray());

        $this->assertSame('restore', $restored->protectedModeOperation);
        $this->assertSame('Maintenance in progress', $restored->protectedModeTitle);
        $this->assertSame('The application is briefly unavailable.', $restored->protectedModeMessage);
    }

    public function testWelcomeNamesTheSessionCookieOfTheDeployment(): void
    {
        // The one thing on the frame the frontend needs in order to WRITE a cookie rather
        // than read one: without the name, a rotation ticket has nowhere to go (HIL-582).
        $welcome = new HandshakeWelcomeSignalData(build: 'dev', sessionCookieName: 'renamed_session');

        $restored = HandshakeWelcomeSignalData::fromArray($welcome->toArray());

        $this->assertSame('renamed_session', $restored->sessionCookieName);
    }

    public function testWelcomeFromArrayReadsAbsentCopyOffAFreezeThatHoldsNone(): void
    {
        // No freeze holds, so the three copy fields are genuinely absent - that is the
        // one shape in which they may be missing, and it arrives as null.
        $restored = HandshakeWelcomeSignalData::fromArray([
            HandshakeWelcomeSignalData::BUILD => 'dev',
            HandshakeWelcomeSignalData::SESSION_COOKIE_NAME => 'hilos_session_token',
            HandshakeWelcomeSignalData::PROTECTED_MODE => [
                HandshakeWelcomeSignalData::PROTECTED_MODE_ACTIVE => false,
                HandshakeWelcomeSignalData::PROTECTED_MODE_ACCEPTS_PASS => false,
                HandshakeWelcomeSignalData::PROTECTED_MODE_PASS_ISSUED => false,
            ],
        ]);

        $this->assertFalse($restored->protectedModeActive);
        $this->assertNull($restored->protectedModeTitle);
        $this->assertNull($restored->protectedModeMessage);
    }

    public function testWelcomeFromArrayRefusesCopyThatIsNotText(): void
    {
        // A block that arrived half-built used to put the unusable field down as absent,
        // which handed the frontend a freeze with no words and no way to tell why.
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(HandshakeWelcomeSignalData::PROTECTED_MODE_TITLE);

        HandshakeWelcomeSignalData::fromArray([
            HandshakeWelcomeSignalData::BUILD => 'dev',
            HandshakeWelcomeSignalData::SESSION_COOKIE_NAME => 'hilos_session_token',
            HandshakeWelcomeSignalData::PROTECTED_MODE => [
                HandshakeWelcomeSignalData::PROTECTED_MODE_ACTIVE => true,
                HandshakeWelcomeSignalData::PROTECTED_MODE_ACCEPTS_PASS => false,
                HandshakeWelcomeSignalData::PROTECTED_MODE_PASS_ISSUED => false,
                HandshakeWelcomeSignalData::PROTECTED_MODE_TITLE => 42,
            ],
        ]);
    }

    public function testWelcomeFromArrayRefusesAFrameWithoutItsProtectedModeBlock(): void
    {
        // A welcome that does not say whether a freeze holds is not a welcome saying
        // none does: read as inactive, it would paint the app over a locked-out node.
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(HandshakeWelcomeSignalData::PROTECTED_MODE);

        HandshakeWelcomeSignalData::fromArray([
            HandshakeWelcomeSignalData::BUILD => 'dev',
            HandshakeWelcomeSignalData::SESSION_COOKIE_NAME => 'hilos_session_token',
        ]);
    }

    public function testWelcomeFromArrayRefusesAMalformedProtectedMode(): void
    {
        $this->expectException(InvalidFormatException::class);

        HandshakeWelcomeSignalData::fromArray([
            HandshakeWelcomeSignalData::BUILD => 'dev',
            HandshakeWelcomeSignalData::SESSION_COOKIE_NAME => 'hilos_session_token',
            HandshakeWelcomeSignalData::PROTECTED_MODE => 'not-an-array',
        ]);
    }

    public function testWelcomeCarriesBothVerificationBits(): void
    {
        // A connection arriving mid-window learns two different things from the same block:
        // that the window is open at all, and whether it has a code to take yet.
        $welcome = new HandshakeWelcomeSignalData(
            build: 'dev',
            sessionCookieName: 'hilos_session_token',
            protectedModeActive: true,
            protectedModeAcceptsPass: true,
            protectedModePassIssued: true,
        );

        $restored = HandshakeWelcomeSignalData::fromArray($welcome->toArray());

        $this->assertTrue($restored->protectedModeAcceptsPass);
        $this->assertTrue($restored->protectedModePassIssued);
    }

    public function testWelcomeInsideAnEmptyWindowSaysNothingIsMinted(): void
    {
        $welcome = new HandshakeWelcomeSignalData(
            build: 'dev',
            sessionCookieName: 'hilos_session_token',
            protectedModeActive: true,
            protectedModeAcceptsPass: true,
        );

        $restored = HandshakeWelcomeSignalData::fromArray($welcome->toArray());

        $this->assertTrue($restored->protectedModeAcceptsPass);
        $this->assertFalse($restored->protectedModePassIssued);
    }

    public function testWelcomeFromArrayRefusesABlockWithoutTheMintedFlag(): void
    {
        // Read as absent it would default to "nothing minted", which is the safe direction on a
        // stub but the wrong one for a verifier holding a key: he would be shown the sentence
        // that says to wait while the field he needs is one bit away.
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(HandshakeWelcomeSignalData::PROTECTED_MODE_PASS_ISSUED);

        HandshakeWelcomeSignalData::fromArray([
            HandshakeWelcomeSignalData::BUILD => 'dev',
            HandshakeWelcomeSignalData::SESSION_COOKIE_NAME => 'hilos_session_token',
            HandshakeWelcomeSignalData::PROTECTED_MODE => [
                HandshakeWelcomeSignalData::PROTECTED_MODE_ACTIVE => true,
                HandshakeWelcomeSignalData::PROTECTED_MODE_ACCEPTS_PASS => true,
            ],
        ]);
    }
}
