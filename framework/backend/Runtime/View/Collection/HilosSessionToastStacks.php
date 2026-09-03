<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Collection;

use Hilos\HilosException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Collection\RtCollectionActionsClassException;
use Hilos\Runtime\Exception\Collection\RtCollectionPropertyNotFoundException;
use Hilos\Runtime\State\Collection\HilosSessionToastStacks as StateHilosSessionToastStacks;
use Hilos\Runtime\State\Item\HilosSessionToastStack as StateHilosSessionToastStack;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Actions\Collection\HilosSessionToastStacksActions;
use Hilos\Runtime\View\Item\HilosSessionToastStack;

/**
 * Read-only wrapper around the live toast stacks (HIL-768).
 *
 * Framework-owned on both halves and mounted for every project. It is read in exactly two
 * places, both inside the agent that owns it: to put a session's stack on the wire, and to
 * walk every stack once a tick looking for a countdown that a since-closed tab was holding up.
 * Its writes belong to the agent that owns the session seam.
 *
 * @extends RtCollection<HilosSessionToastStack, HilosSessionToastStacksActions>
 * @property-read HilosSessionToastStacksActions $actions Actions for write operations
 */
final class HilosSessionToastStacks extends RtCollection
{
    /**
     * @return StateHilosSessionToastStacks Backing state collection
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     */
    public function getStateCollection(): StateHilosSessionToastStacks
    {
        /** @var StateHilosSessionToastStacks */
        return parent::getStateCollection();
    }

    /**
     * @param RtState $state StateHilosSessionToastStack instance
     * @return HilosSessionToastStack View item for this stack
     */
    protected function createRtItem(RtState $state): HilosSessionToastStack
    {
        /** @var StateHilosSessionToastStack $state */
        return new HilosSessionToastStack($state);
    }

    /**
     * @param mixed $offset Hash of a session cookie token
     * @return ?HilosSessionToastStack Item or null
     * @throws RtActionsStateCollectionNullException When the runtime state collection is unavailable
     */
    public function offsetGet(mixed $offset): ?HilosSessionToastStack
    {
        /** @var ?HilosSessionToastStack $item */
        $item = parent::offsetGet($offset);

        return $item;
    }

    /**
     * @return HilosSessionToastStacksActions Actions instance
     * @throws RtCollectionActionsClassException When actions class is missing or invalid
     */
    protected function getActions(): HilosSessionToastStacksActions
    {
        /** @var HilosSessionToastStacksActions $actions */
        $actions = parent::getActions();

        return $actions;
    }

    /**
     * @param string $name Property name
     * @return HilosSessionToastStacksActions Actions instance
     * @throws RtCollectionPropertyNotFoundException When $name is not a declared property
     * @throws RtCollectionActionsClassException When actions class is missing or invalid
     * @throws HilosException Whatever the inherited getter raises
     */
    public function __get(string $name): HilosSessionToastStacksActions
    {
        return match ($name) {
            self::actions => $this->getActions(),
            default => parent::__get($name),
        };
    }
}
