<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Peer;

use Hilos\Cluster\ClusterNode;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\FullMeshConnectionPolicy;
use Hilos\Cluster\Peer\PeerAddress;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the full-mesh connection policy: which known peers the local
 * node dials a direct link to (HIL-342).
 */
final class FullMeshConnectionPolicyTest extends TestCase
{
    public function testDialsEveryReachablePeerButNotItself(): void
    {
        $policy = new FullMeshConnectionPolicy();
        $local = NodeIdentity::of('node-a', NodeRole::Master, [], PeerAddress::fromString('10.0.0.1:8095'));

        // A known-set that includes the local node and two reachable peers: full mesh
        // wants a link to both peers and never to itself.
        $known = [
            $this->reachableNode('node-a', '10.0.0.1:8095'), // the local node
            $this->reachableNode('node-b', '10.0.0.2:8095'),
            $this->reachableNode('node-c', '10.0.0.3:8095'),
        ];

        $dialed = array_map(
            static fn(ClusterNode $node): string => $node->nodeId,
            array_values(array_filter(
                $known,
                static fn(ClusterNode $node): bool => $policy->shouldDial($local, $node),
            )),
        );

        $this->assertSame(['node-b', 'node-c'], $dialed);
    }

    public function testDoesNotDialItself(): void
    {
        $policy = new FullMeshConnectionPolicy();
        $local = NodeIdentity::of('node-a', NodeRole::Master, [], PeerAddress::fromString('10.0.0.1:8095'));

        $this->assertFalse($policy->shouldDial($local, $this->reachableNode('node-a', '10.0.0.1:8095')));
    }

    public function testDoesNotDialAPeerWithoutAnAdvertisedAddress(): void
    {
        $policy = new FullMeshConnectionPolicy();
        $local = NodeIdentity::of('node-a', NodeRole::Master, []);

        $addressless = ClusterNode::fromIdentity(NodeIdentity::of('node-b', NodeRole::Slave, []), true, 100.0);

        $this->assertFalse($policy->shouldDial($local, $addressless));
    }

    private function reachableNode(string $nodeId, string $address): ClusterNode
    {
        return ClusterNode::fromIdentity(
            NodeIdentity::of($nodeId, NodeRole::Slave, [], PeerAddress::fromString($address)),
            true,
            100.0,
        );
    }
}
