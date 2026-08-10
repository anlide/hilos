<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Collection;

use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\State\Collection\HilosConnections as StateHilosConnections;
use Hilos\Runtime\View\Actions\Collection\HilosConnectionsActions;
use Hilos\Runtime\View\DTO\HilosUserPresenceSummary;
use Hilos\Runtime\View\Item\HilosConnection;

/**
 * Read-only wrapper around the connections runtime state — the presence stage (HIL-509).
 *
 * The framework-owned half of a project's connections view: the two user-scoped
 * reads presence is made of, and the {@see HilosPresenceSource} contract the
 * users table merges over its DB rows. A project subclasses this, declares which
 * view item its rows are seen as ({@see createRtItem()}), and adds only what is
 * its own; a project that carries browser sessions subclasses
 * {@see HilosSessionConnections} instead.
 *
 * The presence contract is implemented here rather than left to the project
 * because the summary is the same count in every project, and the interface it
 * satisfies is what the framework finds the source by
 * ({@see RtContext::presenceSource()}).
 *
 * @template TItem of HilosConnection
 * @template TActions of HilosConnectionsActions
 * @extends RtCollection<TItem, TActions>
 */
abstract class HilosConnections extends RtCollection implements HilosPresenceSource
{
    /**
     * @return StateHilosConnections Backing state collection
     * @throws RtActionsStateCollectionNullException When the runtime state collection is unavailable
     */
    public function getStateCollection(): StateHilosConnections
    {
        /** @var StateHilosConnections */
        return parent::getStateCollection();
    }

    /**
     * Returns the connections of one user, as a collection of the same class.
     *
     * The filtered copy holds the same row objects, so a caller reads live rows;
     * it is not attached to the runtime collection name, so it is a read surface
     * and not a second write path into the same rows.
     *
     * @param ?int $userId User id, or null for an empty result
     * @return static Connections of that user
     * @throws RtActionsStateCollectionNullException When the runtime state collection is unavailable
     */
    public function forUser(?int $userId): static
    {
        $stateConnections = $this->getStateCollection();
        $filteredState = $stateConnections::init();
        foreach ($stateConnections->findByUser($userId) as $stateConnection) {
            $filteredState->add($stateConnection);
        }

        $collection = static::init();
        $collection->setStateCollection($filteredState);

        return $collection;
    }

    /**
     * Builds the runtime presence summary used by user-facing table rows.
     *
     * @param ?int $userId User id to summarize active runtime connections for
     * @return HilosUserPresenceSummary Runtime presence and session count summary
     * @throws RtActionsStateCollectionNullException When the runtime state collection is unavailable
     */
    public function summaryForUser(?int $userId): HilosUserPresenceSummary
    {
        return new HilosUserPresenceSummary(count($this->forUser($userId)));
    }
}
