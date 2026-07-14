<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer;

use Hilos\Cluster\ClusterNode;
use Hilos\Cluster\NodeIdentity;

/**
 * Full-mesh policy: dial every known peer, so each node holds a direct link to
 * every other node.
 *
 * This is the default topology while there is no multi-hop routing (HIL-180):
 * quorum heartbeats and votes travel master-to-master over a direct link, so
 * every pair of nodes must be directly connected. The policy wants a link to
 * every node other than the local one that advertises a reachable address; a peer
 * with no advertised address cannot be dialed and is left to reach us inbound.
 */
final class FullMeshConnectionPolicy implements ConnectionPolicy
{
    /**
     * Wants a direct link to every reachable peer that is not the local node.
     *
     * @param NodeIdentity $local Local node identity
     * @param ClusterNode $candidate Known peer weighed as a dial target
     * @return bool True when the candidate is another node with an advertised address
     */
    public function shouldDial(NodeIdentity $local, ClusterNode $candidate): bool
    {
        return $candidate->nodeId !== $local->nodeId && $candidate->address !== null;
    }
}
