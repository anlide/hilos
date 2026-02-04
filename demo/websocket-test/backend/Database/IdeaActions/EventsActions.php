<?php

namespace Demo\WebSocketTest\Database\IdeaActions;

use Demo\WebSocketTest\Database\Entity\Event;
use Demo\WebSocketTest\Database\Idea\Event as IdeaEvent;
use Demo\WebSocketTest\Database\IdeaCollection\Events as IdeaCollectionEvents;
use Demo\WebSocketTest\Database\Object\Event as ObjectEvent;
use Demo\WebSocketTest\Database\ObjectCollection\Events as ObjectCollectionEvents;
use Hilos\Database\Idea\IdeaActions;
use Hilos\Exception\DatabaseException;
use Hilos\Exception\Idea\Actions\IdeaActionsCallbackNotSetException;
use Hilos\Exception\Idea\Actions\IdeaActionsDuplicateIdException;
use Hilos\Exception\Idea\Actions\IdeaActionsObjectCollectionNullException;
use Hilos\Exception\Idea\Actions\IdeaActionsTableNameUndeterminedException;
use Hilos\Exception\Idea\Actions\IdeaActionsUnknownLazyStrategyException;
use Hilos\Exception\Idea\TruthSource\IdeaTruthSourceWriteNotAllowedException;

/**
 * Events Actions
 * Provides write operations for Events collection
 *
 * @extends IdeaActions<IdeaEvent>
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
     * @throws DatabaseException If database operation fails
     * @throws IdeaActionsCallbackNotSetException If callback is not set
     * @throws IdeaActionsUnknownLazyStrategyException If unknown lazy loading strategy
     * @throws IdeaActionsObjectCollectionNullException If ObjectCollection is null
     * @throws IdeaTruthSourceWriteNotAllowedException If write is not allowed
     * @throws IdeaActionsTableNameUndeterminedException If table name cannot be determined
     * @throws IdeaActionsDuplicateIdException If duplicate ID is detected
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
        return $this->createIdeaFromObject($objectEvent);
    }

    /**
     * Delete all events from database and collection
     * Clears collection cache - lazy load will reload on next access
     *
     * @throws IdeaActionsUnknownLazyStrategyException If unknown lazy loading strategy
     * @throws IdeaActionsObjectCollectionNullException If ObjectCollection is null
     * @throws IdeaTruthSourceWriteNotAllowedException If write is not allowed
     * @throws IdeaActionsTableNameUndeterminedException If table name cannot be determined
     * @throws IdeaActionsCallbackNotSetException If callback is not set
     * @throws DatabaseException If database operation fails
     */
    public function deleteAll(): void
    {
        // Check write permissions and load data if needed
        $this->ensureCanWrite();

        $objectCollection = $this->getObjectCollection();
        if ($objectCollection === null) {
            throw new IdeaActionsObjectCollectionNullException("Cannot delete all: ObjectCollection is null");
        }

        if (!($objectCollection instanceof ObjectCollectionEvents)) {
            throw new \InvalidArgumentException("ObjectCollection is not an instance of ObjectCollectionEvents");
        }

        $objectCollection->deleteAll();

        // Notify IdeaCollection about mass change: clear cached IdeaItem instances (via callback)
        $this->clearCollectionCache();
    }
}
