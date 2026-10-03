<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Collection;

use Hilos\HilosException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Collection\RtCollectionActionsClassException;
use Hilos\Runtime\Exception\Collection\RtCollectionPropertyNotFoundException;
use Hilos\Runtime\State\Collection\HilosProfilePhotoChecks as StateHilosProfilePhotoChecks;
use Hilos\Runtime\State\Item\HilosProfilePhotoCheck as StateHilosProfilePhotoCheck;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Actions\Collection\HilosProfilePhotoChecksActions;
use Hilos\Runtime\View\Item\HilosProfilePhotoCheck;

/**
 * @extends RtCollection<HilosProfilePhotoCheck, HilosProfilePhotoChecksActions>
 * @property-read HilosProfilePhotoChecksActions $actions
 */
final class HilosProfilePhotoChecks extends RtCollection
{
    /**
     * @return StateHilosProfilePhotoChecks Backing state collection
     * @throws RtActionsStateCollectionNullException When the runtime collection is unavailable
     */
    public function getStateCollection(): StateHilosProfilePhotoChecks
    {
        /** @var StateHilosProfilePhotoChecks */
        return parent::getStateCollection();
    }

    /**
     * @param RtState $state Backing check row
     * @return HilosProfilePhotoCheck View item for the row
     */
    protected function createRtItem(RtState $state): HilosProfilePhotoCheck
    {
        /** @var StateHilosProfilePhotoCheck $state */
        return new HilosProfilePhotoCheck($state);
    }

    /**
     * @param mixed $offset Connection accept key
     * @return ?HilosProfilePhotoCheck Pending check or null
     * @throws RtActionsStateCollectionNullException When the runtime collection is unavailable
     */
    public function offsetGet(mixed $offset): ?HilosProfilePhotoCheck
    {
        /** @var ?HilosProfilePhotoCheck $item */
        $item = parent::offsetGet($offset);

        return $item;
    }

    /**
     * @return HilosProfilePhotoChecksActions Collection write API
     * @throws RtCollectionActionsClassException When actions are unavailable
     */
    protected function getActions(): HilosProfilePhotoChecksActions
    {
        /** @var HilosProfilePhotoChecksActions $actions */
        $actions = parent::getActions();

        return $actions;
    }

    /**
     * @param string $name Property name
     * @return HilosProfilePhotoChecksActions Collection actions
     * @throws RtCollectionPropertyNotFoundException When $name is not declared
     * @throws RtCollectionActionsClassException When actions are unavailable
     * @throws HilosException Whatever an inherited getter raises
     */
    public function __get(string $name): HilosProfilePhotoChecksActions
    {
        return match ($name) {
            self::actions => $this->getActions(),
            default => parent::__get($name),
        };
    }
}
