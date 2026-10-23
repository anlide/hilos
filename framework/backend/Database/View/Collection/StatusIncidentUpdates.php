<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\Actions\Collection\StatusIncidentUpdatesActions;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\StatusIncidentUpdates as ObjectStatusIncidentUpdates;
use Hilos\Database\View\Item\StatusIncidentUpdate;

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
    public const string DB_ITEM_CLASS = StatusIncidentUpdate::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectStatusIncidentUpdates::class;

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
