<?php

declare(strict_types=1);

namespace Demo\Chat\Database\View\Collection;

use Demo\Chat\Database\Actions\Collection\EventMessagesActions;
use Demo\Chat\Database\Object\Collection\EventMessages as ObjectEventMessages;
use Demo\Chat\Database\View\Item\EventMessage;
use Hilos\Database\View\Collection\DbCollection;

/**
 * EventMessages - Db collection of message event detail items.
 *
 * Array access uses event_id, so eventMessages[$eventId] returns the
 * message detail for that parent event when it exists.
 *
 * @extends DbCollection<EventMessage, ObjectEventMessages>
 * @method ObjectEventMessages|null getObjectCollection()
 * @method EventMessage|null current()
 * @method EventMessage|null first()
 * @method EventMessage|null last()
 * @method EventMessage|null offsetGet(mixed $offset)
 * @property-read EventMessagesActions $actions Actions for write operations
 */
final class EventMessages extends DbCollection
{
    public const string DB_ITEM_CLASS = EventMessage::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectEventMessages::class;
}
