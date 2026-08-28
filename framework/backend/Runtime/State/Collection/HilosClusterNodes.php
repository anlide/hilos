<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Collection;

use Hilos\Runtime\State\Item\HilosClusterNode;
use OutOfBoundsException;

/**
 * HilosClusterNodes - the cluster as this node's master sees it, keyed by node id (HIL-337).
 *
 * Framework-owned state collection mounted for every project: the master writes it, the
 * workers read it. It is never empty on a running node - with clustering off the master
 * publishes itself as the single row - so a reader has one shape to handle rather than two.
 *
 * @extends RtStates<HilosClusterNode>
 */
final class HilosClusterNodes extends RtStates
{
    public const string STATE_CLASS = HilosClusterNode::class;

    /**
     * @param ?string $nodeId Node id, or null for a missing optional key
     * @return ?HilosClusterNode Node row, or null when missing
     */
    public function get(?string $nodeId): ?HilosClusterNode
    {
        /** @var ?HilosClusterNode $state */
        $state = parent::get($nodeId);

        return $state;
    }

    /**
     * Array access is for required rows; use `get()` when absence is valid - and it usually
     * is, because a node this master never saw is the normal answer for any id from outside.
     *
     * @param mixed $offset Node id
     * @return HilosClusterNode Node row
     * @throws OutOfBoundsException When no state is stored under the key
     */
    public function offsetGet(mixed $offset): HilosClusterNode
    {
        if ($offset === null) {
            throw new OutOfBoundsException('Cluster node not found: null');
        }

        return $this->get((string)$offset)
            ?? throw new OutOfBoundsException("Cluster node not found: {$offset}");
    }
}
