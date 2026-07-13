<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Peer;

use Hilos\Cluster\ClusterNode;
use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\DTO\PeerAnnounceDTO;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerNodeEntry;
use Hilos\Cluster\Peer\DTO\PeerRosterDTO;
use Hilos\Cluster\Peer\PeerAddress;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the membership-gossip frames and node entries (HIL-178).
 */
final class PeerGossipDTOTest extends TestCase
{
    public function testNodeEntryRoundTrips(): void
    {
        $entry = new PeerNodeEntry('node-b', NodeRole::Slave, ['cpu'], PeerAddress::fromString('10.0.0.2:9000'), true);

        $restored = PeerNodeEntry::fromArray($entry->toArray());

        $this->assertSame('node-b', $restored->nodeId);
        $this->assertSame(NodeRole::Slave, $restored->role);
        $this->assertSame(['cpu'], $restored->capabilities);
        $this->assertSame('10.0.0.2:9000', $restored->address?->toString());
        $this->assertTrue($restored->online);
    }

    public function testNodeEntryBridgesNodeAndIdentity(): void
    {
        $node = ClusterNode::fromIdentity(
            NodeIdentity::of('node-a', NodeRole::Master, ['gpu-local'], PeerAddress::fromString('10.0.0.1:8095')),
            true,
            100.0,
        );

        $entry = PeerNodeEntry::fromNode($node);
        $this->assertSame('node-a', $entry->nodeId);
        $this->assertTrue($entry->online);

        $identity = $entry->toIdentity();
        $this->assertSame('node-a', $identity->nodeId);
        $this->assertSame(NodeRole::Master, $identity->role);
        $this->assertSame('10.0.0.1:8095', $identity->address?->toString());
    }

    public function testRosterRoundTripsThroughTheWire(): void
    {
        $roster = new PeerRosterDTO([
            new PeerNodeEntry('node-a', NodeRole::Master, ['gpu-local'], PeerAddress::fromString('10.0.0.1:8095'), true),
            new PeerNodeEntry('node-b', NodeRole::Slave, [], null, false),
        ]);

        $parsed = PeerDTO::fromWire($roster->toJson());

        $this->assertInstanceOf(PeerRosterDTO::class, $parsed);
        $this->assertCount(2, $parsed->nodes);
        $this->assertSame('node-a', $parsed->nodes[0]->nodeId);
        $this->assertSame('node-b', $parsed->nodes[1]->nodeId);
        $this->assertFalse($parsed->nodes[1]->online);
        $this->assertNull($parsed->nodes[1]->address);
    }

    public function testAnnounceRoundTripsThroughTheWire(): void
    {
        $announce = new PeerAnnounceDTO(
            new PeerNodeEntry('node-c', NodeRole::Slave, ['cpu'], PeerAddress::fromString('10.0.0.3:9000'), false),
        );

        $parsed = PeerDTO::fromWire($announce->toJson());

        $this->assertInstanceOf(PeerAnnounceDTO::class, $parsed);
        $this->assertSame('node-c', $parsed->node->nodeId);
        $this->assertFalse($parsed->node->online);
    }

    public function testAnnounceRejectsMissingNode(): void
    {
        $this->expectException(PeerTransportException::class);

        PeerDTO::fromWire('{"type":"peer_announce"}');
    }

    public function testNodeEntryRejectsInvalidRole(): void
    {
        $this->expectException(PeerTransportException::class);

        PeerNodeEntry::fromArray([
            PeerNodeEntry::FIELD_NODE_ID => 'node-b',
            PeerNodeEntry::FIELD_NODE_ROLE => 'overlord',
        ]);
    }
}
