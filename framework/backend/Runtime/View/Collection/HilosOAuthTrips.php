<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Collection;

use Hilos\HilosException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Collection\RtCollectionActionsClassException;
use Hilos\Runtime\Exception\Collection\RtCollectionPropertyNotFoundException;
use Hilos\Runtime\State\Collection\HilosOAuthTrips as StateHilosOAuthTrips;
use Hilos\Runtime\State\Item\HilosOAuthTrip as StateHilosOAuthTrip;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Actions\Collection\HilosOAuthTripsActions;
use Hilos\Runtime\View\Item\HilosOAuthTrip;

/**
 * Read-only wrapper around the provider sign-ins tabs are waiting on (HIL-1044).
 *
 * Framework-owned on both halves and mounted by the sign-in feature. Read and written in one
 * place only, the session holder: the agents carrying the exchange report to it by frame and
 * never touch the collection.
 *
 * @extends RtCollection<HilosOAuthTrip, HilosOAuthTripsActions>
 * @property-read HilosOAuthTripsActions $actions Actions for write operations
 */
final class HilosOAuthTrips extends RtCollection
{
    /**
     * @return StateHilosOAuthTrips Backing state collection
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     */
    public function getStateCollection(): StateHilosOAuthTrips
    {
        /** @var StateHilosOAuthTrips */
        return parent::getStateCollection();
    }

    /**
     * @param RtState $state StateHilosOAuthTrip instance
     * @return HilosOAuthTrip View item for this trip
     */
    protected function createRtItem(RtState $state): HilosOAuthTrip
    {
        /** @var StateHilosOAuthTrip $state */
        return new HilosOAuthTrip($state);
    }

    /**
     * @param mixed $offset Hash of a trip key
     * @return ?HilosOAuthTrip Item or null
     * @throws RtActionsStateCollectionNullException When the runtime state collection is unavailable
     */
    public function offsetGet(mixed $offset): ?HilosOAuthTrip
    {
        /** @var ?HilosOAuthTrip $item */
        $item = parent::offsetGet($offset);

        return $item;
    }

    /**
     * @return HilosOAuthTripsActions Actions instance
     * @throws RtCollectionActionsClassException When actions class is missing or invalid
     */
    protected function getActions(): HilosOAuthTripsActions
    {
        /** @var HilosOAuthTripsActions $actions */
        $actions = parent::getActions();

        return $actions;
    }

    /**
     * @param string $name Property name
     * @return HilosOAuthTripsActions Actions instance
     * @throws RtCollectionPropertyNotFoundException When $name is not a declared property
     * @throws RtCollectionActionsClassException When actions class is missing or invalid
     * @throws HilosException Whatever the inherited getter raises
     */
    public function __get(string $name): HilosOAuthTripsActions
    {
        return match ($name) {
            self::actions => $this->getActions(),
            default => parent::__get($name),
        };
    }
}
