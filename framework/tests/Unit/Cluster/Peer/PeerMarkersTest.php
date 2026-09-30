<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Peer;

use Hilos\Cluster\Peer\PeerMarkers;
use Hilos\Core\Exception\InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the rule both ends of a peer link judge each other's markers by (HIL-1206).
 *
 * A kind named by either side must be named by the other with the same value, and a breach is
 * refused in words that name the node, the kind, both values and where this node read its own.
 * The words are pinned literally: they are what an operator reads in the log of both nodes.
 */
final class PeerMarkersTest extends TestCase
{
    /** Node id of the peer whose handshake is judged */
    private const string PEER = 'node-s1';

    /** Database marker this node reads */
    private const string LOCAL_MARKER = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** Database marker a peer on another database reads */
    private const string FOREIGN_MARKER = 'ffffffffffffffffffffffffffffffff';

    /** Where this node reads its marker from */
    private const string PLACE = "database 'hilos_cluster' on db:3306";

    public function testTheSameMarkersPass(): void
    {
        $this->assertNull($this->local()->refusalFor(self::PEER, [PeerMarkers::DATABASE => self::LOCAL_MARKER]));
    }

    public function testAnotherValueOfAKindIsRefused(): void
    {
        $this->assertSame(
            "Peer handshake from node 'node-s1' names database marker 'ffffffffffffffffffffffffffffffff',"
            . " but this node reads 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' from database 'hilos_cluster' on db:3306:"
            . ' the two nodes do not read one database',
            $this->local()->refusalFor(self::PEER, [PeerMarkers::DATABASE => self::FOREIGN_MARKER]),
        );
    }

    public function testAKindThePeerDoesNotNameIsRefused(): void
    {
        $this->assertSame(
            "Peer handshake from node 'node-s1' names no database marker,"
            . " but this node reads 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' from database 'hilos_cluster' on db:3306",
            $this->local()->refusalFor(self::PEER, []),
        );
    }

    public function testAKindOnlyThePeerNamesIsRefused(): void
    {
        $this->assertSame(
            "Peer handshake from node 'node-s1' names catalog marker 'c1', which this node does not carry",
            $this->local()->refusalFor(self::PEER, [PeerMarkers::DATABASE => self::LOCAL_MARKER, 'catalog' => 'c1']),
        );
    }

    public function testTwoNodesCarryingNoMarkersPass(): void
    {
        $this->assertNull(new PeerMarkers([], [])->refusalFor(self::PEER, []));
    }

    public function testAKindWithoutItsPlaceIsNotAMarkerSet(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PeerMarkers([PeerMarkers::DATABASE => self::LOCAL_MARKER], []);
    }

    /**
     * @return PeerMarkers Markers of this node
     */
    private function local(): PeerMarkers
    {
        return new PeerMarkers([PeerMarkers::DATABASE => self::LOCAL_MARKER], [PeerMarkers::DATABASE => self::PLACE]);
    }
}
