<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Database\Actions\Collection\StatusIncidentsActions;
use Hilos\Database\Object\Collection\StatusIncidents as ObjectStatusIncidents;
use Hilos\Database\View\Item\StatusIncident;

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
    public const string DB_ITEM_CLASS = StatusIncident::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectStatusIncidents::class;
}
