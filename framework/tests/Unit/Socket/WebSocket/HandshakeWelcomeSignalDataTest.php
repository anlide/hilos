<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Socket\WebSocket;

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
        $welcome = new HandshakeWelcomeSignalData(build: 'dev');

        $this->assertSame(
            [
                HandshakeWelcomeSignalData::BUILD => 'dev',
                HandshakeWelcomeSignalData::PROTECTED_MODE => [
                    HandshakeWelcomeSignalData::PROTECTED_MODE_ACTIVE => false,
                    HandshakeWelcomeSignalData::PROTECTED_MODE_OPERATION => null,
                    HandshakeWelcomeSignalData::PROTECTED_MODE_TITLE => null,
                    HandshakeWelcomeSignalData::PROTECTED_MODE_MESSAGE => null,
                ],
            ],
            $welcome->toArray(),
        );
    }

    public function testWelcomeCarriesTheActiveFreezeFlag(): void
    {
        $welcome = new HandshakeWelcomeSignalData(build: '20260101', protectedModeActive: true);

        $restored = HandshakeWelcomeSignalData::fromArray($welcome->toArray());

        $this->assertSame('20260101', $restored->build);
        $this->assertTrue($restored->protectedModeActive);
    }

    public function testWelcomeCarriesTheMaintenanceCopyOfTheRunningOperation(): void
    {
        $welcome = new HandshakeWelcomeSignalData(
            build: '20260101',
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

    public function testWelcomeFromArrayIgnoresCopyThatIsNotText(): void
    {
        // A block that arrived half-built must not put a number on the screen: an unusable field
        // reads as absent, and the frontend falls back to its own last-resort sentence.
        $restored = HandshakeWelcomeSignalData::fromArray([
            HandshakeWelcomeSignalData::BUILD => 'dev',
            HandshakeWelcomeSignalData::PROTECTED_MODE => [
                HandshakeWelcomeSignalData::PROTECTED_MODE_ACTIVE => true,
                HandshakeWelcomeSignalData::PROTECTED_MODE_TITLE => 42,
            ],
        ]);

        $this->assertTrue($restored->protectedModeActive);
        $this->assertNull($restored->protectedModeTitle);
        $this->assertNull($restored->protectedModeMessage);
    }

    public function testWelcomeFromArrayTreatsAMissingProtectedModeAsInactive(): void
    {
        $restored = HandshakeWelcomeSignalData::fromArray([
            HandshakeWelcomeSignalData::BUILD => 'dev',
        ]);

        $this->assertSame('dev', $restored->build);
        $this->assertFalse($restored->protectedModeActive);
    }

    public function testWelcomeFromArrayToleratesAMalformedProtectedMode(): void
    {
        $restored = HandshakeWelcomeSignalData::fromArray([
            HandshakeWelcomeSignalData::BUILD => 'dev',
            HandshakeWelcomeSignalData::PROTECTED_MODE => 'not-an-array',
        ]);

        $this->assertFalse($restored->protectedModeActive);
    }
}
