<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Socket\WebSocket;

use Hilos\Socket\WebSocket\DTO\HandshakeWelcomeSignalData;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the handshake welcome payload (HIL-267 slice 6).
 *
 * Pins the wire shape the master sends behind the 101 response: the build timestamp plus the
 * nested protectedMode.active flag the frontend reads to learn it is locked out by a cluster
 * freeze. The master's runtime read that fills the flag is exercised at e2e in demo/cluster;
 * here we lock the serialized shape so the frame never drifts.
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
