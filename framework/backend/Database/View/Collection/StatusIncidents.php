<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Table\DTO\TableFacetCountDTO;
use Hilos\Database\Actions\Collection\StatusIncidentsActions;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\StatusIncidents as ObjectStatusIncidents;
use Hilos\Database\View\Item\StatusIncident;
use Hilos\HilosException;
use Hilos\Status\DTO\StatusHistoryPortion;
use Hilos\Status\DTO\StatusHistoryQuery;

/**
 * Array access uses the persisted primary incident id.
 *
 * @extends DbCollection<StatusIncident, ObjectStatusIncidents>
 * @method ObjectStatusIncidents|null getObjectCollection()
 * @method StatusIncident|null first()
 * @method StatusIncident|null last()
 * @method StatusIncident offsetGet(mixed $offset)
 * @property-read StatusIncidentsActions $actions Storage writes
 */
class StatusIncidents extends DbCollection
{
    public const int MAX_PORTION_ROWS = 32;
    public const string DB_ITEM_CLASS = StatusIncident::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectStatusIncidents::class;

    /**
     * @param int $sinceMs Inclusive resolved boundary in epoch milliseconds UTC
     * @param ?int $afterId Last incident id, or null for the first portion
     * @param int $limit Requested rows, capped at MAX_PORTION_ROWS; nonpositive reads are empty
     * @return static Detached published history portion in ascending id order
     * @throws HilosException When storage is unavailable or a query or selection cannot be initialized
     */
    public function publishedIntersecting(int $sinceMs, ?int $afterId, int $limit): static
    {
        $selection = static::init();
        $selection->setObjectCollection(($this->getObjectCollection()
            ?? throw new DatabaseException('Status history requires a database-backed collection'))
            ->publishedIntersecting($sinceMs, $afterId, $limit));
        return $selection;
    }

    /**
     * @param StatusHistoryQuery $query Validated filters and bounded source window
     * @return StatusHistoryPortion Detached snapshots in canonical order, excluding the edge probe
     * @throws DatabaseException When storage cannot be read or a completed row is invalid
     * @throws ValidationException When the bounded snapshot cannot be formed
     */
    public function historyPortion(StatusHistoryQuery $query): StatusHistoryPortion
    {
        return $this->objectCollection->historyPortion($query);
    }

    /**
     * @param StatusHistoryQuery $query Same predicate and optional tuple side as the portion reader
     * @return TableFacetCountDTO Exact or capped source count, independent of edgeSkip and limit
     * @throws DatabaseException When storage cannot be read
     */
    public function historyCount(StatusHistoryQuery $query): TableFacetCountDTO
    {
        return $this->objectCollection->historyCount($query);
    }

    /**
     * @param int $id Known source primary key
     * @param StatusHistoryQuery $query Same completed/filter/search predicate as the window
     * @return bool Whether this source row belongs to the queried set
     * @throws DatabaseException When storage cannot be read
     */
    public function historyContains(int $id, StatusHistoryQuery $query): bool
    {
        return $this->objectCollection->historyContains($id, $query);
    }

    /**
     * @return int All completed source facts without UI filters
     * @throws DatabaseException When storage cannot be read
     */
    public function completedCount(): int
    {
        return $this->objectCollection->completedCount();
    }

    /**
     * @return list<string> Distinct saved component keys from completed facts
     * @throws DatabaseException When storage cannot be read
     */
    public function historyComponentKeys(): array
    {
        return $this->objectCollection->historyComponentKeys();
    }
}
