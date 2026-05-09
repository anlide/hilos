<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Actions\Collection;

use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Database\Entity\Item\EventMessage;
use Demo\Chat\Database\Object\Collection\EventMessages as ObjectEventMessages;
use Demo\Chat\Database\Object\Item\EventMessage as ObjectEventMessage;
use Demo\Chat\Database\View\Collection\EventMessages as DbCollectionEventMessages;
use Demo\Chat\Database\View\Item\EventMessage as DbEventMessage;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Actions\Collection\DbActions;
use Hilos\HilosException;

/**
 * EventMessagesActions - write operations for message event details.
 *
 * @extends DbActions<DbEventMessage, ObjectEventMessages>
 * @property-read DbCollectionEventMessages $collection
 * @property-read ObjectEventMessages $objectCollection
 */
final class EventMessagesActions extends DbActions
{
    /**
     * Get table name for EventMessages collection.
     *
     * @return string Table name
     */
    protected function getTableName(): string
    {
        return EventMessage::_table;
    }

    /**
     * Creates scalar detail for one message event.
     *
     * @param int $eventId Parent event id
     * @param ?int $authorUserId Authoring user id
     * @param ?int $authorBotId Authoring bot id
     * @param string $message Published message text
     * @return DbEventMessage Created message detail
     * @throws HilosException On database or truth-source failure
     */
    public function create(int $eventId, ?int $authorUserId, ?int $authorBotId, string $message): DbEventMessage
    {
        TruthSourceRegistry::checkCanCreate(DbChatContext::eventMessages);
        $this->ensureCanWrite();

        $detail = ObjectEventMessage::create();
        $detail->eventId = $eventId;
        $detail->authorUserId = $authorUserId;
        $detail->authorBotId = $authorBotId;
        $detail->message = $message;
        $detail->sync();

        $this->addObjectToCollection($detail);

        return $this->createDbItemFromObject($detail);
    }

    /**
     * Deletes every message detail row and clears the collection cache.
     *
     * @throws HilosException On database or truth-source failure
     */
    public function deleteAll(): void
    {
        TruthSourceRegistry::checkCanWrite(DbChatContext::eventMessages);
        $this->ensureCanWrite();

        $this->objectCollection->deleteAll();

        $this->clearCollectionCache();
    }
}
