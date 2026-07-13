<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster;

use Hilos\Cluster\ClusterNode;
use Hilos\Cluster\ClusterRegistry;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\PeerAddress;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the master-owned in-memory cluster membership registry (HIL-178).
 */
final class ClusterRegistryTest extends TestCase
{
    public function testSeedLocalHoldsTheLocalNodeOnline(): void
    {
        $registry = new ClusterRegistry();
        $registry->seedLocal(NodeIdentity::of('node-a', NodeRole::Master, ['gpu-local']), 100.0);

        $snapshot = $registry->snapshot();

        $this->assertCount(1, $snapshot);
        $this->assertSame('node-a', $snapshot[0]->nodeId);
        $this->assertTrue($snapshot[0]->online);
        $this->assertSame(1, $registry->version());
    }

    public function testSeedLocalKeepsTheAdvertisedAddress(): void
    {
        $registry = new ClusterRegistry();
        $registry->seedLocal(
            NodeIdentity::of('node-a', NodeRole::Master, [], PeerAddress::fromString('10.0.0.1:8095')),
            100.0,
        );

        $this->assertSame('10.0.0.1:8095', $registry->snapshot()[0]->address?->toString());
    }

    public function testRecordPeerAddsAndDedupesIdenticalRefresh(): void
    {
        $registry = new ClusterRegistry();
        $registry->seedLocal(NodeIdentity::of('node-a', NodeRole::Master, []), 100.0);
        $registry->recordPeer(NodeIdentity::of('node-b', NodeRole::Slave, ['cpu']), 101.0);

        $this->assertCount(2, $registry->snapshot());
        $this->assertSame(2, $registry->version());

        // Re-recording an identical peer is a no-op: no new row, no version bump.
        $registry->recordPeer(NodeIdentity::of('node-b', NodeRole::Slave, ['cpu']), 105.0);

        $this->assertCount(2, $registry->snapshot());
        $this->assertSame(2, $registry->version());
    }

    public function testRecordPeerBumpsOnMeaningfulChange(): void
    {
        $registry = new ClusterRegistry();
        $registry->recordPeer(NodeIdentity::of('node-b', NodeRole::Slave, ['cpu']), 100.0);
        $this->assertSame(1, $registry->version());

        // A changed capability set is a meaningful change: replace and bump.
        $registry->recordPeer(NodeIdentity::of('node-b', NodeRole::Slave, ['cpu', 'gpu-local']), 105.0);

        $this->assertCount(1, $registry->snapshot());
        $this->assertSame(2, $registry->version());
        $this->assertSame(['cpu', 'gpu-local'], $this->findNode($registry, 'node-b')->capabilities);
    }

    public function testMergeReportsWhetherItChanged(): void
    {
        $registry = new ClusterRegistry();

        $this->assertTrue($registry->merge(NodeIdentity::of('node-b', NodeRole::Slave, []), true, 100.0));
        $this->assertFalse($registry->merge(NodeIdentity::of('node-b', NodeRole::Slave, []), true, 101.0));
        $this->assertTrue($registry->merge(NodeIdentity::of('node-b', NodeRole::Slave, []), false, 102.0));
    }

    public function testMarkOfflineFlipsOnlineAndBumpsVersion(): void
    {
        $registry = new ClusterRegistry();
        $registry->recordPeer(NodeIdentity::of('node-b', NodeRole::Slave, []), 100.0);

        $registry->markOffline('node-b', 110.0);

        $nodeB = $this->findNode($registry, 'node-b');
        $this->assertFalse($nodeB->online);
        $this->assertSame(110.0, $nodeB->lastSeen);
        $this->assertSame(2, $registry->version());
    }

    public function testMarkOfflineIsNoOpForUnknownOrAlreadyOffline(): void
    {
        $registry = new ClusterRegistry();
        $registry->recordPeer(NodeIdentity::of('node-b', NodeRole::Slave, []), 100.0);
        $registry->markOffline('node-b', 110.0);
        $versionAfterOffline = $registry->version();

        $registry->markOffline('node-x', 120.0); // unknown
        $registry->markOffline('node-b', 130.0); // already offline

        $this->assertSame($versionAfterOffline, $registry->version());
    }

    private function findNode(ClusterRegistry $registry, string $nodeId): ClusterNode
    {
        foreach ($registry->snapshot() as $node) {
            if ($node->nodeId === $nodeId) {
                return $node;
            }
        }

        $this->fail("Node {$nodeId} not found in registry snapshot");
    }
}
