<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\StatusIncidentUpdates as EntityStatusIncidentUpdates;
use Hilos\Database\Entity\Item\StatusIncidentUpdate as EntityStatusIncidentUpdate;
use Hilos\Database\Object\Item\StatusIncidentUpdate as ObjectStatusIncidentUpdate;
use Hilos\Database\Object\Objects;

/**
 * @extends Objects<ObjectStatusIncidentUpdate>
 * @method ObjectStatusIncidentUpdate|null first()
 * @method ObjectStatusIncidentUpdate|null last()
 * @method ObjectStatusIncidentUpdate|null get(int|string $key)
 * @method ObjectStatusIncidentUpdate|null offsetGet(mixed $offset)
 */
class StatusIncidentUpdates extends Objects
{
    public const string OBJECT_CLASS = ObjectStatusIncidentUpdate::class;
    public const string ENTITY_COLLECTION_CLASS = EntityStatusIncidentUpdates::class;
    public const string COLLECTION_KEY = HilosDbContext::statusIncidentUpdates;

    /**
     * @param int $incidentId Persisted incident primary id
     * @param string $requestId Correlation id of a team submission
     * @return ?ObjectStatusIncidentUpdate Matching immutable row, or null
     * @throws DatabaseException When the bounded lookup fails
     * @throws InvalidArgumentException When the query declaration is invalid
     */
    public function forRequest(int $incidentId, string $requestId): ?ObjectStatusIncidentUpdate
    {
        $rows = static::entityClass()::get( // fresh-sql: the shared column loader cannot bound a two-column request lookup.
            filters: [EntityStatusIncidentUpdate::incident_id => $incidentId, EntityStatusIncidentUpdate::request_id => $requestId],
            limit: 1,
        );
        foreach ($rows as $id => $entity) {
            if (!isset($this->objects[$id])) {
                $this->hydrate($id, static::OBJECT_CLASS::fromEntity($entity));
            }
            return $this->objects[$id];
        }
        return null;
    }
}
