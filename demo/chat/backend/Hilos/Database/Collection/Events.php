<?php

namespace Demo\Chat\Hilos\Database\Collection;

use Demo\Chat\Database\Object\Collection\Events as ObjectEvents;
use Demo\Chat\Hilos\Database\Item\Event;
use Demo\Chat\Hilos\Database\Actions\EventsActions;
use Hilos\Hilos\Database\Collection\DbCollection;

/**
 * Events Db collection - collection of Event items.
 *
 * @extends DbCollection<Event, ObjectEvents>
 * @method ObjectEvents|null getObjectCollection()
 * @method Event|null current()
 * @method Event|null first()
 * @method Event|null last()
 * @method Event|null offsetGet(mixed $offset)
 * @property-read EventsActions $actions Actions for write operations
 */
final class Events extends DbCollection
{
    public const string DB_ITEM_CLASS = Event::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectEvents::class;
}
