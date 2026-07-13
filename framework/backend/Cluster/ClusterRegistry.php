<?php

declare(strict_types=1);

namespace Hilos\Cluster;

/**
 * Master-owned, in-memory registry of the live cluster membership.
 *
 * This is the single source of truth for "who is in the cluster", held on the
 * daemon master — never in Hilos::$rt and never behind a worker agent: the peer
 * transport and the coordinator both run on the master, so membership lives with
 * them. The local node self-seeds; peers are recorded as their handshakes
 * complete and marked offline when their links close. `version()` bumps on every
 * change so a future master->browser projection (HIL-337) can detect updates and
 * mirror snapshots downstream without the registry becoming a second source of
 * truth. Time is passed in by the caller so the registry stays testable without
 * mocking the clock.
 */
final class ClusterRegistry
{
    /** @var array<string, ClusterNode> Live nodes keyed by node id */
    private array $nodes = [];

    /** @var int Monotonic change counter, bumped on every membership mutation */
    private int $version = 0;

    /**
     * Records the local node as an online member.
     *
     * @param NodeIdentity $self Local node identity
     * @param float $now Current microtime
     */
    public function seedLocal(NodeIdentity $self, float $now): void
    {
        $this->merge($self, true, $now);
    }

    /**
     * Adds or refreshes a peer node as online.
     *
     * @param NodeIdentity $peer Remote node identity learned from a handshake
     * @param float $now Current microtime
     */
    public function recordPeer(NodeIdentity $peer, float $now): void
    {
        $this->merge($peer, true, $now);
    }

    /**
     * Upserts a node and reports whether it changed the membership meaningfully.
     *
     * A change of role, capabilities, address, or online status counts; a
     * repeated identical record does not, so membership gossip converges instead
     * of echoing forever. `lastSeen` alone is never a meaningful change.
     *
     * @param NodeIdentity $node Node identity to upsert
     * @param bool $online Whether the node is currently online
     * @param float $now Current microtime
     * @return bool True when the membership changed meaningfully
     */
    public function merge(NodeIdentity $node, bool $online, float $now): bool
    {
        $existing = $this->nodes[$node->nodeId] ?? null;
        $incoming = ClusterNode::fromIdentity($node, $online, $now);

        if ($existing !== null && !self::isMeaningfulChange($existing, $incoming)) {
            return false;
        }

        $this->nodes[$node->nodeId] = $incoming;
        $this->version++;

        return true;
    }

    /**
     * Marks a known node offline; no-op when it is unknown or already offline.
     *
     * @param string $nodeId Node id that went offline
     * @param float $now Current microtime
     */
    public function markOffline(string $nodeId, float $now): void
    {
        $node = $this->nodes[$nodeId] ?? null;
        if ($node === null || !$node->online) {
            return;
        }

        $this->nodes[$nodeId] = $node->asOffline($now);
        $this->version++;
    }

    /**
     * Reports whether a gossip-relevant field differs between two node records.
     *
     * @param ClusterNode $existing Current node record
     * @param ClusterNode $incoming Candidate node record
     * @return bool True when role, capabilities, address, or online status differs
     */
    private static function isMeaningfulChange(ClusterNode $existing, ClusterNode $incoming): bool
    {
        return $existing->role !== $incoming->role
            || $existing->online !== $incoming->online
            || $existing->capabilities !== $incoming->capabilities
            || $existing->address?->toString() !== $incoming->address?->toString();
    }

    /**
     * Returns the current membership snapshot.
     *
     * TODO(HIL-178): per docs/agents/code-style/internal-backend-api.md ("typed
     * collections for lists of same-kind business objects") this raw list should
     * become a typed ClusterNodeCollection (pattern:
     * Core/Table/Collection/TableMutationSignalCollection). Deferred: the registry
     * is keyed by node id with upsert / mark-offline semantics and will want
     * key-based accessor contracts, so a proper keyed collection is non-trivial
     * and out of this slice.
     *
     * @return list<ClusterNode> Live and recently-offline nodes
     */
    public function snapshot(): array
    {
        return array_values($this->nodes);
    }

    /**
     * @return int Monotonic membership change counter
     */
    public function version(): int
    {
        return $this->version;
    }
}
