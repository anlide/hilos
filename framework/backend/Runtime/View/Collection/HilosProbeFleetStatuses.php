<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Collection;

use Hilos\Runtime\State\Collection\HilosProbeFleetStatuses as StateHilosProbeFleetStatuses;
use Hilos\Runtime\State\Item\HilosProbeFleetStatus as StateHilosProbeFleetStatus;
use Hilos\Runtime\View\Actions\Collection\HilosProbeFleetStatusesActions;
use Hilos\Runtime\View\Item\HilosProbeFleetStatus;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Collection\RtCollectionActionsClassException;
use Hilos\Runtime\Exception\Collection\RtCollectionPropertyNotFoundException;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\RtState;

/**
 * Read-only wrapper around the probe fleet statuses.
 *
 * @extends RtCollection<HilosProbeFleetStatus, HilosProbeFleetStatusesActions>
 * @property-read HilosProbeFleetStatusesActions $actions Actions for write operations
 */
final class HilosProbeFleetStatuses extends RtCollection
{
    /**
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     */
    public function getStateCollection(): StateHilosProbeFleetStatuses
    {
        /** @var StateHilosProbeFleetStatuses */
        return parent::getStateCollection();
    }

    /**
     * @param RtState $state StateHilosProbeFleetStatus instance
     * @return HilosProbeFleetStatus View item for this worker status
     */
    protected function createRtItem(RtState $state): HilosProbeFleetStatus
    {
        /** @var StateHilosProbeFleetStatus $state */
        return new HilosProbeFleetStatus($state);
    }

    /**
     * @param mixed $offset Fleet member index
     * @return ?HilosProbeFleetStatus Item or null
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     */
    public function offsetGet(mixed $offset): ?HilosProbeFleetStatus
    {
        /** @var ?HilosProbeFleetStatus $item */
        $item = parent::offsetGet($offset);

        return $item;
    }

    /**
     * @return HilosProbeFleetStatusesActions Actions instance
     * @throws RtCollectionActionsClassException When actions class is missing or invalid
     */
    protected function getActions(): HilosProbeFleetStatusesActions
    {
        /** @var HilosProbeFleetStatusesActions $actions */
        $actions = parent::getActions();

        return $actions;
    }

    /**
     * @throws RtCollectionPropertyNotFoundException When $name is not a declared property
     * @throws RtCollectionActionsClassException When actions class is missing or invalid
     * @throws HilosException Whatever reading another declared property of the parent raises
     */
    public function __get(string $name): HilosProbeFleetStatusesActions
    {
        return match ($name) {
            self::actions => $this->getActions(),
            default => parent::__get($name),
        };
    }
}
