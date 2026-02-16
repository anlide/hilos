<?php

namespace Demo\Chat\Hilos\Database\Actions;

use Demo\Chat\Database\Entity\Event;
use Demo\Chat\Database\Object\Event as ObjectEvent;
use Demo\Chat\Database\ObjectCollection\Events as ObjectCollectionEvents;
use Demo\Chat\Hilos\Database\Item\Event as DbEvent;
use Demo\Chat\Hilos\Database\Collection\Events as DbCollectionEvents;
use Hilos\Exception\DatabaseException;
use Hilos\Exception\Hilos\Database\Actions\CallbackNotSetException;
use Hilos\Exception\Hilos\Database\Actions\DuplicateIdException;
use Hilos\Exception\Hilos\Database\Actions\ObjectCollectionNullException;
use Hilos\Exception\Hilos\Database\Actions\TableNameUndeterminedException;
use Hilos\Exception\Hilos\Database\Actions\UnknownLazyStrategyException;
use Hilos\Exception\Hilos\Database\TruthSource\WriteNotAllowedException;
use Hilos\Hilos\Database\Actions\DbActions;

/**
 * Events Actions - provides write operations for Events collection.
 *
 * @extends DbActions<DbEvent>
 * @property-read DbCollectionEvents $collection
 */
final class EventsActions extends DbActions
{
    protected function getTableName(): string
    {
        return Event::_table;
    }

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
