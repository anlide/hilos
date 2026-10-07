<?php

declare(strict_types=1);

namespace Hilos\DaemonSection;

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
}
