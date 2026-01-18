<?php

namespace Demo\WebSocketTest\Database\ObjectCollection;

use ArrayAccess;
use Countable;
use Demo\WebSocketTest\Database\Entity\Event as EntityEvent;
use Demo\WebSocketTest\Database\EntityCollection\Events as EntityEvents;
use Demo\WebSocketTest\Database\Object\Event as ObjectEvent;
use Hilos\Database\Database;
use Hilos\Database\Object\Objects;
use Hilos\Exception\DatabaseException;
use Iterator;
use RuntimeException;

/**
 * Events Object Collection
 * Typed wrapper around Objects for Event objects
 *
 * This is a convenience class that provides type safety and convenience methods
 * while using Objects internally.
 */
final class Events extends Objects implements Iterator, ArrayAccess, Countable
{
    /** @var ObjectEvent[] $objects */
    protected array $objects = [];

    /**
     * Load all events from database
     * Clears existing objects and loads all from database
     *
     * @throws DatabaseException
     */
    public function loadAllFromDB(): void
    {
        $this->objects = [];
        $EntityEvents = EntityEvents::initFullDB();
        foreach ($EntityEvents as $key => $EntityEvent) {
            $this->objects[$key] = ObjectEvent::fromEntity($EntityEvent);
        }
        $this->_allLoaded = true;
        $this->_allowLazyLoading = false;
    }

    /**
     * Initialize empty collection
     *
     * @return static
     */
    public static function initEmpty(): static
    {
        $self = new self();
        $self->objects = [];
        return $self;
    }

    /**
     * Get current Event object
     *
     * @return ObjectEvent|null Current Event object or null if invalid position
     */
    public function current(): ?ObjectEvent
    {
        return parent::current();
    }

    /**
     * Set Event object at offset
     *
     * @param mixed $offset
     * @param ObjectEvent $value
     */
    public function offsetSet($offset, $value): void
    {
        if ($value instanceof ObjectEvent) {
            if ($offset === null) {
                $this->objects[] = $value;
            } else {
                $this->objects[$offset] = $value;
            }
        }
    }

    /**
     * Get Event object at offset
     *
     * @param mixed $offset
     * @return ObjectEvent|null
     */
    public function offsetGet($offset): ?ObjectEvent
    {
        return parent::offsetGet($offset);
    }

    /**
     * Lazy load Event object by key
     *
     * @param int|string $key Event ID
     * @return ObjectEvent|null
     * @throws DatabaseException
     */
    protected function lazyLoadObject(int|string $key): ?ObjectEvent
    {
        $EntityEvent = EntityEvent::getById((int)$key);
        return $EntityEvent !== null ? ObjectEvent::fromEntity($EntityEvent) : null;
    }

    /**
     * Lazy load count of Event objects from database
     *
     * @return int
     * @throws DatabaseException
     */
    protected function lazyLoadCount(): int
    {
        // Use Entity to get count
        $resultSetCollection = Database::sql(
            "SELECT COUNT(*) as count FROM `" . EntityEvent::_table . "`"
        );
        $firstResultSet = $resultSetCollection->first();

        if ($firstResultSet === null) {
            return 0;
        }

        $row = $firstResultSet->first();
        return $row !== null ? (int)($row['count'] ?? 0) : 0;
    }

    /**
     * Lazy load all Event objects from database (for batch strategy)
     *
     * @throws DatabaseException
     */
    protected function lazyLoadAll(): void
    {
        $EntityEvents = EntityEvents::initFullDB();

        foreach ($EntityEvents as $key => $EntityEvent) {
            if (!isset($this->objects[$key])) {
                $this->objects[$key] = ObjectEvent::fromEntity($EntityEvent);
            }
        }

        $this->_allLoaded = true;
    }

    /**
     * Delete all events from database
     * Clears collection cache - lazy load will reload on next access
     *
     * @throws DatabaseException
     */
    public function deleteAll(): void
    {
        Database::sql("DELETE FROM `event`;");
        $this->objects = [];
    }

    /**
     * Add event to collection and database
     * Creates ObjectEvent, saves to database, and adds to collection
     *
     * @param string $type Event type
     * @param ?int $userId User ID (null for system events)
     * @param ?array $data Event-specific data (optional, will be JSON encoded)
     * @return ObjectEvent Created event object
     * @throws DatabaseException
     * @throws RuntimeException If event ID is null after sync
     */
    public function add(string $type, ?int $userId = null, ?array $data = null): ObjectEvent
    {
        $objectEvent = ObjectEvent::create();
        $objectEvent->userId = $userId;
        $objectEvent->type = $type;
        $objectEvent->timestamp = date('Y-m-d H:i:s');
        $objectEvent->data = $data === null ? null : json_encode($data);
        $objectEvent->sync();

        if ($objectEvent->id === null) {
            throw new RuntimeException("Failed to save event to database: id is null after sync");
        }

        $this->objects[$objectEvent->id] = $objectEvent;
        return $objectEvent;
    }
}
