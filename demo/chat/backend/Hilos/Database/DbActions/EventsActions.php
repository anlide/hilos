<?php

namespace Demo\Chat\Hilos\Database\DbActions;

use Demo\Chat\Database\Entity\Event;
use Demo\Chat\Hilos\Database\Db\Event as DbEvent;
use Demo\Chat\Hilos\Database\DbCollection\Events as DbCollectionEvents;
use Demo\Chat\Database\Object\Event as ObjectEvent;
use Demo\Chat\Database\ObjectCollection\Events as ObjectCollectionEvents;
use Hilos\Hilos\Database\DbActions;
use Hilos\Exception\DatabaseException;
use Hilos\Exception\Hilos\Database\Actions\CallbackNotSetException;
use Hilos\Exception\Hilos\Database\Actions\DuplicateIdException;
use Hilos\Exception\Hilos\Database\Actions\ObjectCollectionNullException;
use Hilos\Exception\Hilos\Database\Actions\TableNameUndeterminedException;
use Hilos\Exception\Hilos\Database\Actions\UnknownLazyStrategyException;
use Hilos\Exception\Hilos\Database\TruthSource\WriteNotAllowedException;

/**
 * Events Actions - provides write operations for Events collection.
 *
 * @extends DbActions<DbEvent>
 * @property-read DbCollectionEvents $collection
 */
final class EventsActions extends DbActions
{
    /**
     * Get table name for Events collection.
     *
     * @return string Table name
     */
    protected function getTableName(): string
    {
        return Event::_table;
    }

    /**
     * Add event to collection and database.
     *
     * @param string $type Event type
     * @param ?int $userId User ID (null for system events)
     * @param ?array $data Event-specific data (optional, will be JSON encoded)
     * @return DbEvent Created event
     * @throws DatabaseException If database operation fails
     * @throws CallbackNotSetException If callback is not set
     * @throws UnknownLazyStrategyException If unknown lazy loading strategy
     * @throws ObjectCollectionNullException If ObjectCollection is null
     * @throws WriteNotAllowedException If write is not allowed
     * @throws TableNameUndeterminedException If table name cannot be determined
     * @throws DuplicateIdException If duplicate ID is detected
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
        $this->addObjectToCollection($objectEvent);
        return $this->createIdeaFromObject($objectEvent);
    }

    /**
     * Delete all events from database and collection.
     *
     * @throws UnknownLazyStrategyException If unknown lazy loading strategy
     * @throws ObjectCollectionNullException If ObjectCollection is null
     * @throws WriteNotAllowedException If write is not allowed
     * @throws TableNameUndeterminedException If table name cannot be determined
     * @throws CallbackNotSetException If callback is not set
     * @throws DatabaseException If database operation fails
     */
    public function deleteAll(): void
    {
        $this->ensureCanWrite();
        $objectCollection = $this->getObjectCollection();
        if ($objectCollection === null) {
            throw new ObjectCollectionNullException("Cannot delete all: ObjectCollection is null");
        }
        if (!($objectCollection instanceof ObjectCollectionEvents)) {
            throw new \InvalidArgumentException("ObjectCollection is not an instance of ObjectCollectionEvents");
        }
        $objectCollection->deleteAll();
        $this->clearCollectionCache();
    }
}
