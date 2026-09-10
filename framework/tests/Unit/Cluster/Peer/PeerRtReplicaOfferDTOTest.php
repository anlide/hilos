<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Peer;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerRtReplicaOfferDTO;
use Hilos\Cluster\Peer\DTO\PeerRtSnapshotDTO;
use PHPUnit\Framework\TestCase;

/**
 * Tests the frame a holder answers an RT snapshot query with (HIL-823).
 *
 * The row map has to survive the wire exactly, for the same reason it does in
 * {@see PeerRtSnapshotDTO}: an id lost or a row flattened is a row the asker tops its
 * collection up with in a shape its owner never wrote. What differs is the origin id — here it
 * names the node that WROTE the rows rather than the one sending them — and the absence of a
 * scope, which the round trip pins down by leaving the frame's payload to exactly three keys.
 */
final class PeerRtReplicaOfferDTOTest extends TestCase
{
    public function testRoundTripsThroughTheWire(): void
    {
        $frame = new PeerRtReplicaOfferDTO(
            originNodeId: 'node-A',
            collectionKey: 'rooms',
            rows: ['7' => ['id' => '7', 'name' => 'Ada'], 'x8' => ['id' => 'x8', 'name' => 'Grace']],
        );

        $parsed = PeerDTO::fromWire($frame->toJson());

        $this->assertInstanceOf(PeerRtReplicaOfferDTO::class, $parsed);
        $this->assertSame(PeerRtReplicaOfferDTO::MESSAGE_TYPE, $parsed->getType());
        $this->assertSame('node-A', $parsed->originNodeId);
        $this->assertSame('rooms', $parsed->collectionKey);
        $this->assertSame(
            [7 => ['id' => '7', 'name' => 'Ada'], 'x8' => ['id' => 'x8', 'name' => 'Grace']],
            $parsed->rows,
        );
    }

    /**
     * A scope is this protocol's language for deletion and topping up deletes nothing, so the
     * offer must not grow one by accident.
     */
    public function testCarriesNoScope(): void
    {
        $frame = new PeerRtReplicaOfferDTO('node-A', 'rooms', ['7' => ['id' => '7']]);

        $this->assertSame(
            [
                PeerRtReplicaOfferDTO::TYPE,
                PeerRtReplicaOfferDTO::FIELD_ORIGIN_NODE_ID,
                PeerRtReplicaOfferDTO::FIELD_COLLECTION_KEY,
                PeerRtReplicaOfferDTO::FIELD_ROWS,
            ],
            array_keys($frame->toArray()),
        );
    }

    public function testRejectsMissingOriginNodeId(): void
    {
        $this->expectException(PeerTransportException::class);
        $this->expectExceptionMessage('Peer RT replica offer is missing the origin node id');

        PeerRtReplicaOfferDTO::fromArray([
            PeerRtReplicaOfferDTO::FIELD_COLLECTION_KEY => 'rooms',
            PeerRtReplicaOfferDTO::FIELD_ROWS => ['7' => ['id' => '7']],
        ]);
    }

    public function testRejectsMissingCollectionKey(): void
    {
        $this->expectException(PeerTransportException::class);
        $this->expectExceptionMessage('Peer RT replica offer is missing the collection key');

        PeerRtReplicaOfferDTO::fromArray([
            PeerRtReplicaOfferDTO::FIELD_ORIGIN_NODE_ID => 'node-A',
            PeerRtReplicaOfferDTO::FIELD_ROWS => ['7' => ['id' => '7']],
        ]);
    }

    /**
     * Unlike a snapshot of nothing, an offer of nothing is a statement no holder has to make:
     * it stays silent instead, so the empty map on the wire is a defect.
     */
    public function testRejectsAnEmptyRowMap(): void
    {
        $this->expectException(PeerTransportException::class);
        $this->expectExceptionMessage('Peer RT replica offer is missing the offered rows');

        PeerRtReplicaOfferDTO::fromArray([
            PeerRtReplicaOfferDTO::FIELD_ORIGIN_NODE_ID => 'node-A',
            PeerRtReplicaOfferDTO::FIELD_COLLECTION_KEY => 'rooms',
            PeerRtReplicaOfferDTO::FIELD_ROWS => [],
        ]);
    }

    public function testRejectsARowThatIsNotARow(): void
    {
        $this->expectException(PeerTransportException::class);
        $this->expectExceptionMessage("Peer RT replica offer carries a malformed row '7'");

        PeerRtReplicaOfferDTO::fromArray([
            PeerRtReplicaOfferDTO::FIELD_ORIGIN_NODE_ID => 'node-A',
            PeerRtReplicaOfferDTO::FIELD_COLLECTION_KEY => 'rooms',
            PeerRtReplicaOfferDTO::FIELD_ROWS => ['7' => 'Ada'],
        ]);
    }
}
