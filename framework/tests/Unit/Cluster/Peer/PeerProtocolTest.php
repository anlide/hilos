<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Peer;

use Hilos\Cluster\Peer\PeerProtocol;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for peer wire-protocol version compatibility (HIL-178).
 */
final class PeerProtocolTest extends TestCase
{
    public function testCurrentVersionIsCompatible(): void
    {
        $this->assertTrue(PeerProtocol::isCompatible(PeerProtocol::VERSION));
    }

    public function testOtherVersionsAreIncompatible(): void
    {
        $this->assertFalse(PeerProtocol::isCompatible(PeerProtocol::VERSION + 1));
        $this->assertFalse(PeerProtocol::isCompatible(0));
    }

    public function testDialedLinkWinsTieBreakKeepsTheSmallerIdsDial(): void
    {
        // The smaller node id keeps the link it dialed; the larger keeps the one it accepted.
        $this->assertTrue(PeerProtocol::dialedLinkWinsTieBreak('node-a', 'node-b'));
        $this->assertFalse(PeerProtocol::dialedLinkWinsTieBreak('node-b', 'node-a'));
    }

    public function testDialedLinkTieBreakIsSymmetricAcrossThePair(): void
    {
        // Both ends must reach opposite keep/drop verdicts, or the pair drops both or keeps both.
        $localId = 'alpha';
        $remoteId = 'omega';

        $this->assertNotSame(
            PeerProtocol::dialedLinkWinsTieBreak($localId, $remoteId),
            PeerProtocol::dialedLinkWinsTieBreak($remoteId, $localId),
        );
    }
}
