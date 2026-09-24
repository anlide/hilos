<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Actions\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\HilosException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Collection\HilosOAuthTrips as StateHilosOAuthTrips;
use Hilos\Runtime\State\Item\HilosOAuthTrip as StateHilosOAuthTrip;
use Hilos\Runtime\View\Actions\Item\HilosOAuthTripActions;
use Hilos\Runtime\View\Collection\HilosOAuthTrips;
use Hilos\Runtime\View\Item\HilosOAuthTrip;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * Write API for the provider sign-ins tabs are waiting on, as a set (HIL-1044).
 *
 * Two methods: a trip is opened, and the endings nobody needs any more are reclaimed. What
 * happens to one trip in between - its ending, a new connection presenting its key - is a write
 * over a row the holder has already found, and lives on {@see HilosOAuthTripActions}.
 *
 * @extends RtActions<HilosOAuthTrip, HilosOAuthTrips, StateHilosOAuthTrips>
 * @property-read StateHilosOAuthTrips $stateCollection
 */
final class HilosOAuthTripsActions extends RtActions
{
    /**
     * Opens one trip, with no ending.
     *
     * A key already kept is left alone: the key is 128 random bits minted by the tab, so a
     * second open under it is the same callback arriving twice, and the row that is there - with
     * the ending it may already carry - is the one to keep.
     *
     * @param string $tripKeyHash Hash of the key the tab minted
     * @param string $sessionTokenHash Hash of the session cookie token the callback came in under
     * @param string $acceptKey Accept key of the connection that sent the callback
     * @param string $mode Flow mode of the exchange
     * @param string $provider Provider key
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws SourceChangeSubscriberException Whatever a subscriber to the collection's announcement raises
     * @throws HilosException Whatever the row's read of the written fields raises
     */
    public function open(
        string $tripKeyHash,
        string $sessionTokenHash,
        string $acceptKey,
        string $mode,
        string $provider,
    ): void {
        $this->ensureCanWrite();

        if ($this->stateCollection->has($tripKeyHash)) {
            return;
        }

        $this->addStateToCollection(StateHilosOAuthTrip::create(
            $tripKeyHash,
            $sessionTokenHash,
            $acceptKey,
            $mode,
            $provider,
            TimeHelper::nowMs(),
        ));
    }

    /**
     * Drops the trips that ended long enough ago that nobody is coming back for their outcome.
     *
     * Only trips WITH an ending are ever reclaimed here. One still going is waited on by a tab,
     * and ending it by the clock is precisely what this row exists to stop doing: it is ended by
     * a fact - an agent's answer, an agent gone, the holder's own restart - and by nothing else.
     *
     * @param int $maxAgeMs How long an ended trip may go unwritten before it is reclaimed
     * @return int Number of trips dropped
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws SourceChangeSubscriberException Whatever a subscriber to the collection's announcement raises
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     * @throws HilosException Whatever the row's read of the written fields raises
     */
    public function forgetEnded(int $maxAgeMs): int
    {
        $this->ensureCanWrite();

        $oldestKept = TimeHelper::nowMs() - $maxAgeMs;

        $dropped = 0;
        foreach ($this->stateCollection as $state) {
            if ($state->ending === null || $state->updatedAt >= $oldestKept) {
                continue;
            }

            $this->removeStateFromCollection($state->getId());
            $dropped++;
        }

        return $dropped;
    }
}
