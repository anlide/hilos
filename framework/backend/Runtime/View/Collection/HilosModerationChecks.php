<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Collection;

use Hilos\HilosException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Collection\RtCollectionActionsClassException;
use Hilos\Runtime\Exception\Collection\RtCollectionPropertyNotFoundException;
use Hilos\Runtime\State\Collection\HilosModerationChecks as StateHilosModerationChecks;
use Hilos\Runtime\State\Item\HilosModerationCheck as StateHilosModerationCheck;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Actions\Collection\HilosModerationChecksActions;
use Hilos\Runtime\View\Item\HilosModerationCheck;

/**
 * Read-only wrapper around the checks connections are waiting on (HIL-1625).
 *
 * Mounted by the moderation feature in every process: the moderator reads every check here, and
 * each initiator writes only the checks of its own set.
 *
 * @extends RtCollection<HilosModerationCheck, HilosModerationChecksActions>
 * @property-read HilosModerationChecksActions $actions Actions for write operations
 */
final class HilosModerationChecks extends RtCollection
{
    /**
     * @return StateHilosModerationChecks Backing state collection
     * @throws RtActionsStateCollectionNullException When the runtime collection is unavailable
     */
    public function getStateCollection(): StateHilosModerationChecks
    {
        /** @var StateHilosModerationChecks */
        return parent::getStateCollection();
    }

    /**
     * @param string $acceptKey Accept key of the connection
     * @param string $kind Moderation kind key
     * @return ?HilosModerationCheck The check, or null when the connection waits on none of that kind
     * @throws RtActionsStateCollectionNullException When the runtime collection is unavailable
     */
    public function find(string $acceptKey, string $kind): ?HilosModerationCheck
    {
        return $this[StateHilosModerationCheck::keyFor($acceptKey, $kind)];
    }

    /**
     * @param RtState $state Backing check row
     * @return HilosModerationCheck View item for the row
     */
    protected function createRtItem(RtState $state): HilosModerationCheck
    {
        /** @var StateHilosModerationCheck $state */
        return new HilosModerationCheck($state);
    }

    /**
     * @param mixed $offset Row id `acceptKey|kind`
     * @return ?HilosModerationCheck Check or null
     * @throws RtActionsStateCollectionNullException When the runtime collection is unavailable
     */
    public function offsetGet(mixed $offset): ?HilosModerationCheck
    {
        /** @var ?HilosModerationCheck $item */
        $item = parent::offsetGet($offset);

        return $item;
    }

    /**
     * @return HilosModerationChecksActions Collection write API
     * @throws RtCollectionActionsClassException When actions are unavailable
     */
    protected function getActions(): HilosModerationChecksActions
    {
        /** @var HilosModerationChecksActions $actions */
        $actions = parent::getActions();

        return $actions;
    }

    /**
     * @param string $name Property name
     * @return HilosModerationChecksActions Collection actions
     * @throws RtCollectionPropertyNotFoundException When $name is not declared
     * @throws RtCollectionActionsClassException When actions are unavailable
     * @throws HilosException Whatever an inherited getter raises
     */
    public function __get(string $name): HilosModerationChecksActions
    {
        return match ($name) {
            self::actions => $this->getActions(),
            default => parent::__get($name),
        };
    }
}
