<?php

namespace Demo\Chat\Hilos\Database\Actions;

use Demo\Chat\Database\Entity\Item\Event;
use Demo\Chat\Database\Object\Collection\Events as ObjectEvents;
use Demo\Chat\Database\Object\Item\Event as ObjectEvent;
use Demo\Chat\Hilos\Database\Collection\Events as DbCollectionEvents;
use Demo\Chat\Hilos\Database\Item\Event as DbEvent;
use Hilos\Hilos\Database\Actions\DbActions;
use Hilos\Hilos\Database\Actions\Exception\Old\ObjectCollectionNullException;
use RuntimeException;

/**
 * Events Actions - write operations for Events collection.
 *
 * @extends DbActions<DbEvent, ObjectEvents>
 * @method ObjectEvents|null getObjectCollection()
 * @property-read DbCollectionEvents $collection
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
     * @param ?int $userId User ID (optional)
     * @param ?array $data Additional event data (optional)
     * @return DbEvent Created event
     * @throws ObjectCollectionNullException If ObjectCollection is null
     * @throws RuntimeException If event id is null after sync
     */
    public function add(string $type, ?int $userId = null, ?array $data = null): DbEvent
    {
        $this->ensureCanWrite();

        $objectEvent = ObjectEvent::create();
        $objectEvent->userId = $userId;
        $objectEvent->type = $type;
        $objectEvent->timestamp = date('Y-m-d H:i:s');
        $objectEvent->data = $data === null ? null : json_encode($data);
        $objectEvent->sync();

        if ($objectEvent->id === null) {
            throw new RuntimeException("Failed to save event to database: id is null after sync");
        }

        $this->addObjectToCollection($objectEvent);
        return $this->createIdeaFromObject($objectEvent);
    }

    /**
     * Deletes all events from database and clears the collection.
     *
     * @throws ObjectCollectionNullException If ObjectCollection is null
     */
    public function deleteAll(): void
    {
        $this->ensureCanWrite();
        $objectCollection = $this->getObjectCollection();
        if ($objectCollection === null) {
            throw new ObjectCollectionNullException("Cannot delete all: ObjectCollection is null");
        }
        $objectCollection->deleteAll();
        $this->clearCollectionCache();
    }
}
