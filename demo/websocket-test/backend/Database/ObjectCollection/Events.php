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
     * Initialize collection with all Event objects from database
     *
     * @return self
     * @throws DatabaseException
     */
    public static function initFullDB(): self
    {
        $self = new self();
        $EntityEvents = EntityEvents::initFullDB();

        foreach ($EntityEvents as $key => $EntityEvent) {
            $self->objects[$key] = ObjectEvent::fromEntity($EntityEvent);
        }

        return $self;
    }

    /**
     * Initialize collection with partial database loading (lazy loading enabled)
     *
     * @param int $strategy Lazy loading strategy (LAZY_STRATEGY_BATCH by default)
     * @return self
     */
    public static function initPartialDB(int $strategy = self::LAZY_STRATEGY_BATCH): self
    {
        $self = new self();
        $self->_allowLazyLoading = true;
        $self->_lazyStrategy = $strategy;
        $self->_allLoaded = false;
        return $self;
    }

    /**
     * Initialize empty collection
     *
     * @return self
     */
    public static function initEmpty(): self
    {
        $self = new self();
        $self->objects = [];
        return $self;
    }

    /**
     * Reload all Event objects from database
     *
     * @throws DatabaseException
     */
    public function initAgainFullDB(): void
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
}
