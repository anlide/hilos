<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Collection;

use Hilos\Runtime\State\Collection\HilosStatusComponents as StateHilosStatusComponents;
use Hilos\Runtime\State\Item\HilosStatusComponent as StateHilosStatusComponent;
use Hilos\Runtime\View\Actions\Collection\HilosStatusComponentsActions;
use Hilos\Runtime\View\Item\HilosStatusComponent;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Collection\RtCollectionActionsClassException;
use Hilos\Runtime\Exception\Collection\RtCollectionPropertyNotFoundException;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\RtState;

/**
 * Read-only wrapper around the Status component counts.
 *
 * @extends RtCollection<HilosStatusComponent, HilosStatusComponentsActions>
 * @property-read HilosStatusComponentsActions $actions Actions for write operations
 */
final class HilosStatusComponents extends RtCollection
{
    /**
     * @return StateHilosStatusComponents Backing component counts
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     */
    public function getStateCollection(): StateHilosStatusComponents
    {
        /** @var StateHilosStatusComponents */
        return parent::getStateCollection();
    }

    /**
     * @param mixed $offset Component key
     * @return ?HilosStatusComponent Item or null
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     */
    public function offsetGet(mixed $offset): ?HilosStatusComponent
    {
        /** @var ?HilosStatusComponent $item */
        $item = parent::offsetGet($offset);

        return $item;
    }

    /**
     * @param string $name Collection actions property
     * @return HilosStatusComponentsActions Component collection actions
     * @throws RtCollectionPropertyNotFoundException When $name is not a declared property
     * @throws RtCollectionActionsClassException When actions class is missing or invalid
     * @throws HilosException Whatever reading another declared property of the parent raises
     */
    public function __get(string $name): HilosStatusComponentsActions
    {
        return match ($name) {
            self::actions => $this->getActions(),
            default => parent::__get($name),
        };
    }

    /**
     * @param RtState $state StateHilosStatusComponent instance
     * @return HilosStatusComponent Read-only component counts
     */
    protected function createRtItem(RtState $state): HilosStatusComponent
    {
        /** @var StateHilosStatusComponent $state */
        return new HilosStatusComponent($state);
    }

    /**
     * @return HilosStatusComponentsActions Actions instance
     * @throws RtCollectionActionsClassException When actions class is missing or invalid
     */
    protected function getActions(): HilosStatusComponentsActions
    {
        /** @var HilosStatusComponentsActions $actions */
        $actions = parent::getActions();

        return $actions;
    }
}
