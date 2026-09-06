<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Collection;

use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\State\Collection\HilosConnections as StateHilosConnections;
use Hilos\Runtime\View\Actions\Collection\HilosConnectionsActions;
use Hilos\Runtime\View\Context\RtContext;
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
     * The freshness is asked of THIS collection and never of what {@see self::forUser()}
     * hands back. That copy is deliberately detached from the runtime collection name, and
     * a mark is kept beside the rows under that name — so every item of the copy answers
     * "fresh" whatever the link is doing, and a summary built out of it would be a green
     * light wired to nothing (HIL-800).
     *
     * One frozen connection is enough for the whole summary: the count it qualifies is a
     * single number over all of them, and a number partly assembled out of copies nobody
     * is hearing about is not a fresh number.
     *
     * @param ?int $userId User id to summarize active runtime connections for
     * @return HilosUserPresenceSummary Runtime presence and session count summary
     * @throws RtActionsStateCollectionNullException When the runtime state collection is unavailable
     */
    public function summaryForUser(?int $userId): HilosUserPresenceSummary
    {
        $stateConnections = $this->getStateCollection()->findByUser($userId);
        $stale = false;
        foreach (array_keys($stateConnections) as $stateId) {
            if ($this->getRtItemForKey((string) $stateId)?->staleSince() !== null) {
                $stale = true;

                break;
            }
        }

        return new HilosUserPresenceSummary(count($stateConnections), $stale);
    }
}
