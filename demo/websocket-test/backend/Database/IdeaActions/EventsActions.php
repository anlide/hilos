<?php

namespace Demo\WebSocketTest\Database\IdeaActions;

use Demo\WebSocketTest\Database\Entity\Event;
use Demo\WebSocketTest\Database\Idea\Event as IdeaEvent;
use Demo\WebSocketTest\Database\IdeaCollection\Events as IdeaCollectionEvents;
use Demo\WebSocketTest\Database\Object\Event as ObjectEvent;
use Demo\WebSocketTest\Database\ObjectCollection\Events as ObjectCollectionEvents;
use Hilos\Database\Idea\IdeaActions;
use Hilos\Exception\DatabaseException;
use RuntimeException;

/**
 * Events Actions
 * Provides write operations for Events collection
 *
 * @property-read IdeaCollectionEvents $collection
 */
final class EventsActions extends IdeaActions
{
    /**
     * Get table name for Events collection
     * Overrides parent to provide table name when collection is empty
     *
     * @return string Table name
     */
    protected function getTableName(): string
    {
        return Event::_table;
    }

    /**
     * Add event to collection and database
     * Creates ObjectEvent, saves to database, and adds to collection
     * 
     * For LAZY_STRATEGY_NONE: Only allows write if truth source is registered.
     * Ensures all data is loaded before write operation.
     *
     * @param string $type Event type
     * @param ?int $userId User ID (null for system events)
     * @param ?array $data Event-specific data (optional, will be JSON encoded)
     * @return IdeaEvent Created event idea
     * @throws DatabaseException
     * @throws RuntimeException If event ID is null after sync or if write is not allowed
     */
    public function add(string $type, ?int $userId = null, ?array $data = null): IdeaEvent
    {
        // Check write permissions and load data if needed
        $this->ensureCanWrite();
        
        // Create and save ObjectEvent
        $objectEvent = ObjectEvent::create();
        $objectEvent->userId = $userId;
        $objectEvent->type = $type;
        $objectEvent->timestamp = date('Y-m-d H:i:s');
        $objectEvent->data = $data === null ? null : json_encode($data);
        $objectEvent->sync();

        // Add to ObjectCollection (modifies storage directly via reference)
        // getIdString() will throw exception if ID is null
        $this->addObjectToCollection($objectEvent);

        // Create and return IdeaEvent using callback
        /** @var IdeaEvent $ideaEvent */
        $ideaEvent = $this->createIdeaFromObject($objectEvent);
        return $ideaEvent;
    }

    /**
     * Delete all events from database and collection
     * Clears collection cache - lazy load will reload on next access
     *
     * @throws DatabaseException
     */
    public function deleteAll(): void
    {
        // Check write permissions and load data if needed
        $this->ensureCanWrite();

        $objectCollection = $this->getObjectCollection();
        if ($objectCollection === null) {
            throw new DatabaseException("Cannot delete all: ObjectCollection is null");
        }
        
        if (!($objectCollection instanceof ObjectCollectionEvents)) {
            throw new DatabaseException("ObjectCollection is not an instance of ObjectCollectionEvents");
        }
        
        $objectCollection->deleteAll();

        // Notify IdeaCollection about mass change: clear cached IdeaItem instances (via callback)
        $this->clearCollectionCache();
    }
}
