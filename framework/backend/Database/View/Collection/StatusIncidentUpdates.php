<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\Actions\Collection\StatusIncidentUpdatesActions;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\StatusIncidentUpdates as ObjectStatusIncidentUpdates;
use Hilos\Database\View\Item\StatusIncidentUpdate;
use Hilos\HilosException;

/**
 * Array access uses the persisted primary incident update id.
 *
 * @extends DbCollection<StatusIncidentUpdate, ObjectStatusIncidentUpdates>
 * @method ObjectStatusIncidentUpdates|null getObjectCollection()
 * @method StatusIncidentUpdate|null first()
 * @method StatusIncidentUpdate|null last()
 * @method StatusIncidentUpdate offsetGet(mixed $offset)
 * @property-read StatusIncidentUpdatesActions $actions Storage writes
 */
class StatusIncidentUpdates extends DbCollection
{
    public const int MAX_PORTION_ROWS = 64;
    public const string DB_ITEM_CLASS = StatusIncidentUpdate::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectStatusIncidentUpdates::class;

    /**
     * @param int $incidentId Persisted parent incident id; only this owner set is read
     * @param ?int $afterId Last update id in descending time/id order, or null at the start
     * @param int $limit Requested rows, capped at MAX_PORTION_ROWS; nonpositive reads are empty
     * @return static Detached timeline portion; empty for a missing or foreign anchor
     * @throws HilosException When storage is unavailable or a query or selection cannot be initialized
     */
    public function timelinePortion(int $incidentId, ?int $afterId, int $limit): static
    {
        $selection = static::init();
        $selection->setObjectCollection(($this->getObjectCollection()
            ?? throw new DatabaseException('Status timeline requires a database-backed collection'))
            ->timelinePortion($incidentId, $afterId, $limit));
        return $selection;
    }

    /**
     * @param int $incidentId Persisted incident primary id
     * @param string $requestId Correlation id of a team submission
     * @return ?StatusIncidentUpdate Matching immutable row, or null
     * @throws DatabaseException When the lookup fails
     * @throws InvalidArgumentException When the query or loaded object is invalid
     * @throws LogicException When the collection classes are not configured
     */
    public function forRequest(int $incidentId, string $requestId): ?StatusIncidentUpdate
    {
        return $this->getItemForKey($this->objectCollection->forRequest($incidentId, $requestId)?->id);
    }
}
