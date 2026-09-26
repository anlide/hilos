<?php

declare(strict_types=1);

namespace Demo\Chat\Database\View\Collection;

use Demo\Chat\Database\Actions\Collection\EventMessagesActions;
use Demo\Chat\Database\Entity\Item\EventMessage as EntityEventMessage;
use Demo\Chat\Database\Object\Collection\EventMessages as ObjectEventMessages;
use Demo\Chat\Database\View\Item\EventMessage;
use Hilos\Database\SqlSortDirection;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\HilosException;

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

    /**
     * The event ids of every message a person wrote (HIL-302).
     *
     * The erasure asks it first: the attachments of these messages have to go before the
     * messages, and their events after them.
     *
     * @param int $userId Author user id
     * @return list<int> Event ids of the person's messages, in id order
     * @throws HilosException On database failure
     */
    public function eventIdsByAuthor(int $userId): array
    {
        $messages = EntityEventMessage::get(
            [EntityEventMessage::author_user_id => $userId],
            [],
            [EntityEventMessage::event_id => SqlSortDirection::ASC],
        );
        $ids = [];
        foreach ($messages as $entity) {
            $ids[] = $entity->event_id;
        }

        return $ids;
    }
}
