<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Collection;

use Hilos\HilosException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Collection\RtCollectionActionsClassException;
use Hilos\Runtime\Exception\Collection\RtCollectionPropertyNotFoundException;
use Hilos\Runtime\State\Collection\HilosClusterNodes as StateHilosClusterNodes;
use Hilos\Runtime\State\Item\HilosClusterNode as StateHilosClusterNode;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Actions\Collection\HilosClusterNodesActions;
use Hilos\Runtime\View\Item\HilosClusterNode;

/**
 * Read-only wrapper around the cluster as this node's master sees it (HIL-337).
 *
 * Framework-owned on both halves, mounted for every project. It adds no read method of its
 * own: what a consumer wants from it is the nodes, and walking the collection is already
 * that. Writes belong to the master alone.
 *
 * @extends RtCollection<HilosClusterNode, HilosClusterNodesActions>
 * @property-read HilosClusterNodesActions $actions Actions for write operations
 */
final class HilosClusterNodes extends RtCollection
{
    /**
     * @return StateHilosClusterNodes Backing state collection
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     */
    public function getStateCollection(): StateHilosClusterNodes
    {
        /** @var StateHilosClusterNodes */
        return parent::getStateCollection();
    }

    /**
     * @param RtState $state StateHilosClusterNode instance
     * @return HilosClusterNode View item for this node
     */
    protected function createRtItem(RtState $state): HilosClusterNode
    {
        /** @var StateHilosClusterNode $state */
        return new HilosClusterNode($state);
    }

    /**
     * @param mixed $offset Node id
     * @return ?HilosClusterNode Item or null
     * @throws RtActionsStateCollectionNullException When the runtime state collection is unavailable
     */
    public function offsetGet(mixed $offset): ?HilosClusterNode
    {
        /** @var ?HilosClusterNode $item */
        $item = parent::offsetGet($offset);

        return $item;
    }

    /**
     * @return HilosClusterNodesActions Actions instance
     * @throws RtCollectionActionsClassException When actions class is missing or invalid
     */
    protected function getActions(): HilosClusterNodesActions
    {
        /** @var HilosClusterNodesActions $actions */
        $actions = parent::getActions();

        return $actions;
    }

    /**
     * @param string $name Property name
     * @return HilosClusterNodesActions Actions instance
     * @throws RtCollectionPropertyNotFoundException When $name is not a declared property
     * @throws RtCollectionActionsClassException When actions class is missing or invalid
     * @throws HilosException Whatever the inherited getter raises
     */
    public function __get(string $name): HilosClusterNodesActions
    {
        return match ($name) {
            self::actions => $this->getActions(),
            default => parent::__get($name),
        };
    }
}
