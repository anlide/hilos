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
}
