<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Database\Actions\Collection\StatusIncidentsActions;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\StatusIncidents as ObjectStatusIncidents;
use Hilos\Database\View\Item\StatusIncident;
use Hilos\HilosException;

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
}
