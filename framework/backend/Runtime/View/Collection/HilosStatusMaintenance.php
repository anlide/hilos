<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Collection;

use Hilos\Runtime\State\Collection\HilosStatusMaintenance as StateHilosStatusMaintenanceCollection;
use Hilos\Runtime\State\Item\HilosStatusMaintenance as StateHilosStatusMaintenance;
use Hilos\Runtime\View\Actions\Collection\HilosStatusMaintenanceActions;
use Hilos\Runtime\View\Item\HilosStatusMaintenance as ViewHilosStatusMaintenance;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Collection\RtCollectionActionsClassException;
use Hilos\Runtime\Exception\Collection\RtCollectionPropertyNotFoundException;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\RtState;

/**
 * Read-only wrapper around the Status maintenance facts.
 *
 * @extends RtCollection<ViewHilosStatusMaintenance, HilosStatusMaintenanceActions>
 * @property-read HilosStatusMaintenanceActions $actions Actions for write operations
 */
final class HilosStatusMaintenance extends RtCollection
{
    /**
     * @return StateHilosStatusMaintenanceCollection Backing maintenance facts
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     */
    public function getStateCollection(): StateHilosStatusMaintenanceCollection
    {
        /** @var StateHilosStatusMaintenanceCollection */
        return parent::getStateCollection();
    }

    /**
     * @param mixed $offset Singleton key
     * @return ?ViewHilosStatusMaintenance Item or null
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     */
    public function offsetGet(mixed $offset): ?ViewHilosStatusMaintenance
    {
        /** @var ?ViewHilosStatusMaintenance $item */
        $item = parent::offsetGet($offset);

        return $item;
    }

    /**
     * @param string $name Collection actions property
     * @return HilosStatusMaintenanceActions Maintenance collection actions
     * @throws RtCollectionPropertyNotFoundException When $name is not a declared property
     * @throws RtCollectionActionsClassException When actions class is missing or invalid
     * @throws HilosException Whatever reading another declared property of the parent raises
     */
    public function __get(string $name): HilosStatusMaintenanceActions
    {
        return match ($name) {
            self::actions => $this->getActions(),
            default => parent::__get($name),
        };
    }

    /**
     * @param RtState $state StateHilosStatusMaintenance instance
     * @return ViewHilosStatusMaintenance Read-only maintenance facts
     */
    protected function createRtItem(RtState $state): ViewHilosStatusMaintenance
    {
        /** @var StateHilosStatusMaintenance $state */
        return new ViewHilosStatusMaintenance($state);
    }

    /**
     * @return HilosStatusMaintenanceActions Actions instance
     * @throws RtCollectionActionsClassException When actions class is missing or invalid
     */
    protected function getActions(): HilosStatusMaintenanceActions
    {
        /** @var HilosStatusMaintenanceActions $actions */
        $actions = parent::getActions();

        return $actions;
    }
}
