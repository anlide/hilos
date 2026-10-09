<?php

declare(strict_types=1);

namespace Hilos\DaemonSection;

use Hilos\Cluster\Consensus\ConsensusRole;
use Hilos\Cluster\NodeRole;

/** Immutable cluster picture, ordered by node id regardless of frame arrival order. */
final class ClusterDaemonPicture
{
    /** @var array<string, ClusterDaemonNodeView> */
    private readonly array $nodes;

    /** @param array<string, ClusterDaemonNodeView> $nodes */
    private function __construct(array $nodes)
    {
        ksort($nodes);
        $this->nodes = $nodes;
    }

    /** @return self Picture with no nodes */
    public static function empty(): self
    {
        return new self([]);
    }

    /** @return self Picture with this node replaced whole */
    public function withNode(ClusterDaemonNodeView $node): self
    {
        $nodes = $this->nodes;
        $nodes[$node->nodeId] = $node;

        return new self($nodes);
    }

    /** @return list<ClusterDaemonNodeView> All nodes in id order */
    public function nodes(): array
    {
        return array_values($this->nodes);
    }

    /** @return ?ClusterDaemonNodeView Node if known */
    public function node(string $nodeId): ?ClusterDaemonNodeView
    {
        return $this->nodes[$nodeId] ?? null;
    }

    /** @return ?ClusterDaemonNodeView Highest-term live master claiming leadership */
    public function leader(): ?ClusterDaemonNodeView
    {
        $maxTerm = null;
        $leader = null;
        foreach ($this->nodes as $node) {
            $picture = $node->slot?->picture;
            $consensus = $picture?->standing?->consensus;
            if (!$node->online || $picture?->role !== NodeRole::Master || $consensus === null) {
                continue;
            }
            if ($maxTerm === null || $consensus->term > $maxTerm) {
                $maxTerm = $consensus->term;
                $leader = null;
            }
            if ($consensus->term === $maxTerm && $consensus->role === ConsensusRole::Leader && $leader === null) {
                $leader = $node;
            }
        }
        return $leader;
    }

    /**
     * @param string $nodeId Node to describe
     * @return ?DaemonNodeState State word, null until this cluster node has enough facts
     */
    public function stateOf(string $nodeId): ?DaemonNodeState
    {
        $node = $this->node($nodeId);
        if ($node === null) {
            return null;
        }
        if (!$node->online) {
            return DaemonNodeState::Silent;
        }
        $picture = $node->slot?->picture;
        if ($picture === null) {
            return null;
        }
        if ($picture->role === NodeRole::Slave) {
            return DaemonNodeState::Data;
        }
        if ($picture->standing === null || !$picture->standing->clustered) {
            return null;
        }
        return $this->leader()?->nodeId === $nodeId ? DaemonNodeState::Leader : DaemonNodeState::Standby;
    }
}
