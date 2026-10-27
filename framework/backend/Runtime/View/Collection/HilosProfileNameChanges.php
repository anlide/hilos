<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Collection;

use Hilos\HilosException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Collection\RtCollectionActionsClassException;
use Hilos\Runtime\Exception\Collection\RtCollectionPropertyNotFoundException;
use Hilos\Runtime\State\Collection\HilosProfileNameChanges as StateHilosProfileNameChanges;
use Hilos\Runtime\State\Item\HilosProfileNameChange as StateHilosProfileNameChange;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Actions\Collection\HilosProfileNameChangesActions;
use Hilos\Runtime\View\Item\HilosProfileNameChange;

/**
 * Session name-change attempts, addressed directly by session token hash.
 *
 * @extends RtCollection<HilosProfileNameChange, HilosProfileNameChangesActions>
 * @property-read HilosProfileNameChangesActions $actions Actions for write operations
 */
final class HilosProfileNameChanges extends RtCollection
{
    /**
     * @return StateHilosProfileNameChanges Backing state collection
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     */
    public function getStateCollection(): StateHilosProfileNameChanges
    {
        /** @var StateHilosProfileNameChanges */
        return parent::getStateCollection();
    }

    /**
     * @param RtState $state StateHilosProfileNameChange instance
     * @return HilosProfileNameChange View item for this attempt
     */
    protected function createRtItem(RtState $state): HilosProfileNameChange
    {
        /** @var StateHilosProfileNameChange $state */
        return new HilosProfileNameChange($state);
    }

    /**
     * @param mixed $offset Session token hash
     * @return ?HilosProfileNameChange Item or null
     * @throws RtActionsStateCollectionNullException When the runtime state collection is unavailable
     */
    public function offsetGet(mixed $offset): ?HilosProfileNameChange
    {
        /** @var ?HilosProfileNameChange $item */
        $item = parent::offsetGet($offset);

        return $item;
    }

    /**
     * @return HilosProfileNameChangesActions Actions instance
     * @throws RtCollectionActionsClassException When actions class is missing or invalid
     */
    protected function getActions(): HilosProfileNameChangesActions
    {
        /** @var HilosProfileNameChangesActions $actions */
        $actions = parent::getActions();

        return $actions;
    }

    /**
     * @param string $name Property name
     * @return HilosProfileNameChangesActions Actions instance
     * @throws RtCollectionPropertyNotFoundException When $name is not a declared property
     * @throws RtCollectionActionsClassException When actions class is missing or invalid
     * @throws HilosException Whatever the inherited getter raises
     */
    public function __get(string $name): HilosProfileNameChangesActions
    {
        return match ($name) {
            self::actions => $this->getActions(),
            default => parent::__get($name),
        };
    }
}
