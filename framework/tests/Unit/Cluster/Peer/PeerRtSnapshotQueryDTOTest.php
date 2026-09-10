<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Peer;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerRtSnapshotQueryDTO;
use PHPUnit\Framework\TestCase;

/**
 * Tests the frame a node with an empty copy sends to ask the mesh for a collection (HIL-823).
 *
 * What has to survive the wire is the pair the answer is built from: who is asking, so the
 * holder knows where to address its rows, and which collections are being asked about. The
 * refusals matter as much as the round trip, because both a frame with nobody behind it and a
 * frame naming nothing would otherwise be read as a request for a holder's whole runtime.
 */
final class PeerRtSnapshotQueryDTOTest extends TestCase
{
    public function testRoundTripsThroughTheWire(): void
    {
        $frame = new PeerRtSnapshotQueryDTO(nodeId: 'node-C', collectionKeys: ['rooms', 'presence']);

        $parsed = PeerDTO::fromWire($frame->toJson());

        $this->assertInstanceOf(PeerRtSnapshotQueryDTO::class, $parsed);
        $this->assertSame(PeerRtSnapshotQueryDTO::MESSAGE_TYPE, $parsed->getType());
        $this->assertSame('node-C', $parsed->nodeId);
        $this->assertSame(['rooms', 'presence'], $parsed->collectionKeys);
    }

    public function testRejectsMissingNodeId(): void
    {
        $this->expectException(PeerTransportException::class);
        $this->expectExceptionMessage('Peer RT snapshot query is missing the node id');

        PeerRtSnapshotQueryDTO::fromArray([
            PeerRtSnapshotQueryDTO::FIELD_COLLECTION_KEYS => ['rooms'],
        ]);
    }

    public function testRejectsMissingCollectionKeys(): void
    {
        $this->expectException(PeerTransportException::class);
        $this->expectExceptionMessage('Peer RT snapshot query is missing the collection keys');

        PeerRtSnapshotQueryDTO::fromArray([
            PeerRtSnapshotQueryDTO::FIELD_NODE_ID => 'node-C',
        ]);
    }

    /**
     * Silence is how a node with nothing to ask about says so, so an empty list on the wire is
     * malformed rather than a request for everything the holder keeps.
     */
    public function testRejectsAnEmptyCollectionList(): void
    {
        $this->expectException(PeerTransportException::class);
        $this->expectExceptionMessage('Peer RT snapshot query names no collection');

        PeerRtSnapshotQueryDTO::fromArray([
            PeerRtSnapshotQueryDTO::FIELD_NODE_ID => 'node-C',
            PeerRtSnapshotQueryDTO::FIELD_COLLECTION_KEYS => [],
        ]);
    }

    public function testRejectsACollectionKeyThatIsNotAKey(): void
    {
        $this->expectException(PeerTransportException::class);
        $this->expectExceptionMessage('Peer RT snapshot query carries a malformed collection key');

        PeerRtSnapshotQueryDTO::fromArray([
            PeerRtSnapshotQueryDTO::FIELD_NODE_ID => 'node-C',
            PeerRtSnapshotQueryDTO::FIELD_COLLECTION_KEYS => ['rooms', ['presence']],
        ]);
    }
}
