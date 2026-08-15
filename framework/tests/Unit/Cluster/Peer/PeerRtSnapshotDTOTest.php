<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Peer;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerRtSnapshotDTO;
use PHPUnit\Framework\TestCase;

/**
 * Tests the RT hand-over peer frame (HIL-586).
 *
 * The frame carries one whole collection to a node that just joined, so what has to survive
 * the wire is the row map exactly: an id lost or a row flattened is a receiver replacing its
 * copy with something the owner does not hold. An empty map is a legitimate snapshot — the
 * owner holds nothing — and is the case a "missing rows" check would silently turn into an
 * error.
 */
final class PeerRtSnapshotDTOTest extends TestCase
{
    public function testRoundTripsThroughTheWire(): void
    {
        $frame = new PeerRtSnapshotDTO(
            originNodeId: 'node-A',
            collectionKey: 'rooms',
            rows: ['7' => ['id' => '7', 'name' => 'Ada'], 'x8' => ['id' => 'x8', 'name' => 'Grace']],
        );

        $parsed = PeerDTO::fromWire($frame->toJson());

        $this->assertInstanceOf(PeerRtSnapshotDTO::class, $parsed);
        $this->assertSame(PeerRtSnapshotDTO::MESSAGE_TYPE, $parsed->getType());
        $this->assertSame('node-A', $parsed->originNodeId);
        $this->assertSame('rooms', $parsed->collectionKey);
        $this->assertSame(
            [7 => ['id' => '7', 'name' => 'Ada'], 'x8' => ['id' => 'x8', 'name' => 'Grace']],
            $parsed->rows,
        );
    }

    /**
     * An owner that holds nothing says so, and the receiver is meant to end up holding nothing
     * too — which is why this is a snapshot and not a missing field.
     */
    public function testAnEmptyCollectionIsCarriedAsASnapshotOfNothing(): void
    {
        $frame = new PeerRtSnapshotDTO('node-A', 'rooms', []);

        $parsed = PeerDTO::fromWire($frame->toJson());

        $this->assertInstanceOf(PeerRtSnapshotDTO::class, $parsed);
        $this->assertSame([], $parsed->rows);
    }

    public function testRejectsMissingOriginNodeId(): void
    {
        $this->expectException(PeerTransportException::class);
        $this->expectExceptionMessage('Peer RT snapshot is missing the origin node id');

        PeerRtSnapshotDTO::fromArray([
            PeerRtSnapshotDTO::FIELD_COLLECTION_KEY => 'rooms',
            PeerRtSnapshotDTO::FIELD_ROWS => [],
        ]);
    }

    public function testRejectsMissingCollectionKey(): void
    {
        $this->expectException(PeerTransportException::class);
        $this->expectExceptionMessage('Peer RT snapshot is missing the collection key');

        PeerRtSnapshotDTO::fromArray([
            PeerRtSnapshotDTO::FIELD_ORIGIN_NODE_ID => 'node-A',
            PeerRtSnapshotDTO::FIELD_ROWS => [],
        ]);
    }

    public function testRejectsMissingRows(): void
    {
        $this->expectException(PeerTransportException::class);
        $this->expectExceptionMessage('Peer RT snapshot is missing the collection rows');

        PeerRtSnapshotDTO::fromArray([
            PeerRtSnapshotDTO::FIELD_ORIGIN_NODE_ID => 'node-A',
            PeerRtSnapshotDTO::FIELD_COLLECTION_KEY => 'rooms',
        ]);
    }

    public function testRejectsARowThatIsNotARow(): void
    {
        $this->expectException(PeerTransportException::class);
        $this->expectExceptionMessage("Peer RT snapshot carries a malformed row '7'");

        PeerRtSnapshotDTO::fromArray([
            PeerRtSnapshotDTO::FIELD_ORIGIN_NODE_ID => 'node-A',
            PeerRtSnapshotDTO::FIELD_COLLECTION_KEY => 'rooms',
            PeerRtSnapshotDTO::FIELD_ROWS => ['7' => 'Ada'],
        ]);
    }
}
