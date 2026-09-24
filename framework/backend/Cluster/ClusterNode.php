<?php

declare(strict_types=1);

namespace Hilos\Cluster;

use Hilos\Cluster\Peer\PeerAddress;

/**
 * Immutable snapshot of one cluster node's identity and liveness.
 *
 * Held by the master-owned {@see ClusterRegistry} as a value object, replaced
 * wholesale on every membership change rather than mutated. `address` is the
 * endpoint peers dial to reach the node; `online` and `lastSeen` describe
 * liveness as this master observed it over its own link. Only three events set
 * `online`: this node's own handshake with the node, the close of its last link to
 * it, and the leave frame the node sends about itself; a neighbour's gossip never
 * does (HIL-1059). Per-link keepalive detects a hung node and marks it offline
 * through the same close path (HIL-183); this snapshot's shape is unchanged.
 */
final class ClusterNode
{
    /**
     * @param string $nodeId Node id
     * @param NodeRole $role Node role
     * @param list<string> $capabilities Declared capability tags
     * @param ?PeerAddress $address Advertised address peers dial to reach the node
     * @param bool $online Whether the node is currently connected
     * @param float $lastSeen Microtime the node was last observed; for a node known only from
     *                        gossip, the moment this node learned of it
     */
    private function __construct(
        public readonly string $nodeId,
        public readonly NodeRole $role,
        public readonly array $capabilities,
        public readonly ?PeerAddress $address,
        public readonly bool $online,
        public readonly float $lastSeen,
    ) {
    }

    /**
     * Builds a node record from a resolved identity.
     *
     * @param NodeIdentity $identity Node identity
     * @param bool $online Whether the node is currently connected
     * @param float $lastSeen Microtime the node was last observed; for a node known only from
     *                        gossip, the moment this node learned of it
     * @return self Node record
     */
    public static function fromIdentity(NodeIdentity $identity, bool $online, float $lastSeen): self
    {
        return new self($identity->nodeId, $identity->role, $identity->capabilities, $identity->address, $online, $lastSeen);
    }

    /**
     * Returns a copy of this node marked offline as of the given time.
     *
     * @param float $lastSeen Microtime the node went offline
     * @return self Offline copy
     */
    public function asOffline(float $lastSeen): self
    {
        return new self($this->nodeId, $this->role, $this->capabilities, $this->address, false, $lastSeen);
    }
}
