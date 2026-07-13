<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster;

use Hilos\Cluster\ClusterNode;
use Hilos\Cluster\ClusterRegistry;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
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

    public function testRecordPeerAddsAndRefreshes(): void
    {
        $registry = new ClusterRegistry();
        $registry->seedLocal(NodeIdentity::of('node-a', NodeRole::Master, []), 100.0);
        $registry->recordPeer(NodeIdentity::of('node-b', NodeRole::Slave, ['cpu']), 101.0);

        $this->assertCount(2, $registry->snapshot());
        $this->assertSame(2, $registry->version());

        // Refreshing the same peer keeps one row but updates lastSeen and bumps version.
        $registry->recordPeer(NodeIdentity::of('node-b', NodeRole::Slave, ['cpu']), 105.0);

        $this->assertCount(2, $registry->snapshot());
        $this->assertSame(3, $registry->version());
        $nodeB = $this->findNode($registry, 'node-b');
        $this->assertSame(105.0, $nodeB->lastSeen);
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
