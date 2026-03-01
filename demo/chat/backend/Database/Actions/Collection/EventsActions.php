<?php

namespace Demo\Chat\Database\Actions\Collection;

use Demo\Chat\Database\Entity\Item\Event;
use Demo\Chat\Database\Object\Collection\Events as ObjectEvents;
use Demo\Chat\Database\Object\Item\Event as ObjectEvent;
use Demo\Chat\Database\View\Collection\Events as DbCollectionEvents;
use Demo\Chat\Database\View\Item\Event as DbEvent;
use Hilos\Database\Actions\Collection\DbActions;
use Hilos\HilosException;
use Hilos\Utils\Helpers\TimeHelper;
use RuntimeException;

/**
 * Events Actions - write operations for Events collection.
 *
 * @extends DbActions<DbEvent, ObjectEvents>
 * @property-read DbCollectionEvents $collection
 * @property-read ObjectEvents $objectCollection
 */
final class EventsActions extends DbActions
{
    protected function getTableName(): string
    {
        return Event::_table;
    }

    /**
     * Adds new event to collection and persists to database.
     *
     * @param string $type Event type
     * @param ?int $userId User ID (optional, for user-authored events)
     * @param ?int $botId Bot ID (optional, for bot-authored events; mutually exclusive with userId)
     * @param ?array $data Additional event data (optional)
     * @return DbEvent Created event
     * @throws HilosException On error (invalid parameters, database error, etc.)
     */
    public function add(string $type, ?int $userId = null, ?int $botId = null, ?array $data = null): DbEvent
    {
        $this->ensureCanWrite();

        $objectEvent = ObjectEvent::create();
        $objectEvent->userId = $userId;
        $objectEvent->botId = $botId;
        $objectEvent->type = $type;
        $objectEvent->timestamp = TimeHelper::getSqlDateTime();
        $objectEvent->data = $data === null ? null : json_encode($data);
        $objectEvent->sync();

        if ($objectEvent->id === null) {
            throw new RuntimeException("Failed to save event to database: id is null after sync");
        }

        $this->addObjectToCollection($objectEvent);
        return $this->createDbItemFromObject($objectEvent);
    }

    /**
     * Deletes all events from database and clears the collection.
     *
     * @throws HilosException On error (permissions error, database error, etc.)
     */
    public function deleteAll(): void
    {
        $this->ensureCanWrite();

        $this->objectCollection->deleteAll();

        $this->clearCollectionCache();
    }
}
