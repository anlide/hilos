<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Peer;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerNodeLeavingDTO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the graceful-leave peer frame (HIL-341).
 */
final class PeerNodeLeavingDTOTest extends TestCase
{
    public function testLeaderLeaveRoundTripsThroughTheWire(): void
    {
        $frame = new PeerNodeLeavingDTO('node-a', true, 'node-b');

        $decoded = PeerDTO::fromWire($frame->toJson());

        $this->assertInstanceOf(PeerNodeLeavingDTO::class, $decoded);
        $this->assertSame('node-a', $decoded->nodeId);
        $this->assertTrue($decoded->wasLeader);
        $this->assertSame('node-b', $decoded->designatedSuccessor);
    }

    public function testFollowerLeaveCarriesNoSuccessor(): void
    {
        $frame = new PeerNodeLeavingDTO('node-c', false, null);

        $decoded = PeerDTO::fromWire($frame->toJson());

        $this->assertInstanceOf(PeerNodeLeavingDTO::class, $decoded);
        $this->assertFalse($decoded->wasLeader);
        $this->assertNull($decoded->designatedSuccessor);
    }

    public function testBlankSuccessorNormalizesToNull(): void
    {
        $decoded = PeerNodeLeavingDTO::fromArray([
            PeerNodeLeavingDTO::TYPE => PeerNodeLeavingDTO::MESSAGE_TYPE,
            PeerNodeLeavingDTO::FIELD_NODE_ID => 'node-a',
            PeerNodeLeavingDTO::FIELD_WAS_LEADER => true,
            PeerNodeLeavingDTO::FIELD_DESIGNATED_SUCCESSOR => '',
        ]);

        $this->assertNull($decoded->designatedSuccessor);
    }

    public function testMissingNodeIdIsRejected(): void
    {
        $this->expectException(PeerTransportException::class);

        PeerNodeLeavingDTO::fromArray([
            PeerNodeLeavingDTO::TYPE => PeerNodeLeavingDTO::MESSAGE_TYPE,
            PeerNodeLeavingDTO::FIELD_WAS_LEADER => false,
        ]);
    }
}
