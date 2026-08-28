<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Peer;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerSourceInterestDTO;
use PHPUnit\Framework\TestCase;

/**
 * Tests the node-level reader-interest peer frame (HIL-717).
 *
 * What the frame decides is whether a fact is worth a hop, so the things that have to survive
 * the wire are the node it speaks for and the two lists it carries - one per kind of source,
 * kept apart, because a runtime collection and a database collection may share a name and mean
 * two different things (HIL-750). An empty list is a real
 * announcement - this node reads nothing any more - and a missing one says the same thing, which
 * is what a node of an older build says by not knowing the field. A missing node id is neither:
 * a list with nobody behind it cannot be written into the map without overwriting somebody
 * else's, so the frame is refused.
 */
final class PeerSourceInterestDTOTest extends TestCase
{
    public function testRoundTripsThroughTheWire(): void
    {
        $frame = new PeerSourceInterestDTO(
            nodeId: 'node-A',
            rtCollections: ['rooms', 'connections'],
            dbCollections: ['sessions'],
        );

        $parsed = PeerDTO::fromWire($frame->toJson());

        $this->assertInstanceOf(PeerSourceInterestDTO::class, $parsed);
        $this->assertSame(PeerSourceInterestDTO::MESSAGE_TYPE, $parsed->getType());
        $this->assertSame('node-A', $parsed->nodeId);
        $this->assertSame(['rooms', 'connections'], $parsed->rtCollections);
        $this->assertSame(['sessions'], $parsed->dbCollections);
    }

    /**
     * The announcement a node makes when its last reader has gone, and the one a frame filter
     * has to act on: read as "nothing changed" it would keep sending facts nobody applies.
     */
    public function testAnEmptyListIsAnAnnouncementAndNotAnAbsence(): void
    {
        $frame = new PeerSourceInterestDTO('node-A', [], []);

        $parsed = PeerDTO::fromWire($frame->toJson());

        $this->assertInstanceOf(PeerSourceInterestDTO::class, $parsed);
        $this->assertSame([], $parsed->rtCollections);
        $this->assertSame([], $parsed->dbCollections);
    }

    public function testAFrameWithoutAListReadsAsReadingNothing(): void
    {
        $parsed = PeerSourceInterestDTO::fromArray([
            PeerSourceInterestDTO::FIELD_NODE_ID => 'node-A',
        ]);

        $this->assertSame([], $parsed->rtCollections);
        $this->assertSame([], $parsed->dbCollections);
    }

    /**
     * A node reading a runtime collection and a database collection of the same name says so
     * twice, once in each list: a frame that merged them would let a database fact cross the mesh
     * to a node that only holds the runtime rows of that name, and the other way round.
     */
    public function testTheTwoListsAreNotMergedByName(): void
    {
        $parsed = PeerSourceInterestDTO::fromArray([
            PeerSourceInterestDTO::FIELD_NODE_ID => 'node-A',
            PeerSourceInterestDTO::FIELD_RT_COLLECTIONS => ['rooms'],
            PeerSourceInterestDTO::FIELD_DB_COLLECTIONS => ['rooms', 'sessions'],
        ]);

        $this->assertSame(['rooms'], $parsed->rtCollections);
        $this->assertSame(['rooms', 'sessions'], $parsed->dbCollections);
    }

    public function testDropsEntriesThatAreNotCollectionKeys(): void
    {
        $parsed = PeerSourceInterestDTO::fromArray([
            PeerSourceInterestDTO::FIELD_NODE_ID => 'node-A',
            PeerSourceInterestDTO::FIELD_RT_COLLECTIONS => ['rooms', '', 17, ['nested'], 'connections'],
        ]);

        $this->assertSame(['rooms', 'connections'], $parsed->rtCollections);
    }

    public function testRejectsMissingNodeId(): void
    {
        $this->expectException(PeerTransportException::class);
        $this->expectExceptionMessage('Peer source interest is missing the node id');

        PeerSourceInterestDTO::fromArray([
            PeerSourceInterestDTO::FIELD_RT_COLLECTIONS => ['rooms'],
        ]);
    }
}
