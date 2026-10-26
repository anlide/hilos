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
use Hilos\Database\SqlSortDirection;
use Hilos\Database\View\Collection\StatusIncidentUpdates as ViewStatusIncidentUpdates;
use Hilos\HilosException;

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
     * Reads the incident_id owner set without loading its unbounded relation or retaining rows.
     * An anchor from another incident or a missing anchor produces an empty portion.
     *
     * @param int $incidentId Persisted parent incident id
     * @param ?int $afterId Last update id in descending time/id order, or null at the start
     * @param int $limit Requested rows, capped at the timeline portion size
     * @return static Detached timeline portion in descending at/id order
     * @throws HilosException When query execution or selection initialization fails
     */
    public function timelinePortion(int $incidentId, ?int $afterId, int $limit): static
    {
        $selection = static::initEmpty();
        $limit = max(0, min(ViewStatusIncidentUpdates::MAX_PORTION_ROWS, $limit));
        if ($limit === 0) {
            return $selection;
        }
        $where = '`incident_id` = ?';
        $params = [$incidentId];
        if ($afterId !== null) {
            $anchor = static::entityClass()::get( // fresh-sql: the timeline keyset requires the anchor time from this owner set.
                filters: [EntityStatusIncidentUpdate::incident_id => $incidentId, EntityStatusIncidentUpdate::id => $afterId],
                limit: 1,
            )->first();
            if ($anchor === null) {
                return $selection;
            }
            $where .= ' AND (`at` < ? OR (`at` = ? AND `id` < ?))';
            array_push($params, $anchor->at, $anchor->at, $afterId);
        }
        $rows = static::entityClass()::get( // fresh-sql: the shared column loader cannot bound a timeline by time/id keyset.
            filters: $where,
            filtersParam: $params,
            orderBy: [EntityStatusIncidentUpdate::at => SqlSortDirection::DESC, EntityStatusIncidentUpdate::id => SqlSortDirection::DESC],
            limit: $limit,
        );
        foreach ($rows as $id => $entity) {
            $selection->hydrate($id, static::OBJECT_CLASS::fromEntity($entity));
        }
        return $selection;
    }

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
