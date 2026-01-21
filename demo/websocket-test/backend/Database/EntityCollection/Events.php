<?php

namespace Demo\WebSocketTest\Database\EntityCollection;

use Demo\WebSocketTest\Database\Entity\Event as EntityEvent;
use Hilos\Database\Entity\EntityCollection;
use Hilos\Exception\DatabaseException;

/**
 * Events Entity Collection
 * Typed wrapper around EntityCollection for Event entities
 *
 * This is a convenience class that provides type safety and convenience methods
 * while using EntityCollection internally.
 */
final class Events implements \Iterator
{
    private EntityCollection $collection;

    /**
     * Private constructor
     */
    private function __construct(EntityCollection $collection)
    {
        $this->collection = $collection;
    }

    /**
     * Initialize collection with all Event entities from database
     *
     * @return self
     * @throws DatabaseException
     */
    public static function initFullDB(): self
    {
        $entityCollection = EntityEvent::getAll();
        return new self($entityCollection);
    }

    /**
     * Initialize empty collection
     *
     * @return self
     */
    public static function initEmpty(): self
    {
        return new self(EntityCollection::empty());
    }

    /**
     * Create from EntityCollection
     *
     * @param EntityCollection $collection
     * @return self
     */
    public static function fromEntityCollection(EntityCollection $collection): self
    {
        return new self($collection);
    }

    /**
     * Get underlying EntityCollection
     *
     * @return EntityCollection
     */
    public function getCollection(): EntityCollection
    {
        return $this->collection;
    }

    /**
     * Get Event entity by key
     *
     * @param int|string $key
     * @return EntityEvent|null
     */
    public function get(int|string $key): ?EntityEvent
    {
        $entity = $this->collection->get($key);
        return $entity instanceof EntityEvent ? $entity : null;
    }

    /**
     * Get first Event entity
     *
     * @return EntityEvent|null
     */
    public function first(): ?EntityEvent
    {
        $entity = $this->collection->first();
        return $entity instanceof EntityEvent ? $entity : null;
    }

    /**
     * Get last Event entity
     *
     * @return EntityEvent|null
     */
    public function last(): ?EntityEvent
    {
        $entity = $this->collection->last();
        return $entity instanceof EntityEvent ? $entity : null;
    }

    /**
     * Get count of Event entities
     */
    public function count(): int
    {
        return $this->collection->count();
    }

    /**
     * Check if collection is empty
     */
    public function isEmpty(): bool
    {
        return $this->collection->count() === 0;
    }

    /**
     * Filter collection
     *
     * @param callable(EntityEvent): bool $callback
     * @return self
     */
    public function filter(callable $callback): self
    {
        $filtered = $this->collection->filter(function($entity) use ($callback) {
            return $entity instanceof EntityEvent && $callback($entity);
        });
        return new self($filtered);
    }

    /**
     * Convert to array
     *
     * @return EntityEvent[]
     */
    public function toArray(): array
    {
        $result = [];
        foreach ($this->collection as $entity) {
            if ($entity instanceof EntityEvent) {
                $result[] = $entity;
            }
        }
        return $result;
    }

    /**
     * Iterator support - get current Event
     *
     * @return EntityEvent|null
     */
    public function current(): ?EntityEvent
    {
        $entity = $this->collection->current();
        return $entity instanceof EntityEvent ? $entity : null;
    }

    /**
     * Iterator support - get current key
     */
    public function key(): int|string|null
    {
        return $this->collection->key();
    }

    /**
     * Iterator support - move to next
     */
    public function next(): void
    {
        $this->collection->next();
    }

    /**
     * Iterator support - rewind
     */
    public function rewind(): void
    {
        $this->collection->rewind();
    }

    /**
     * Iterator support - check if valid
     */
    public function valid(): bool
    {
        return $this->collection->valid();
    }

    /**
     * ArrayAccess support - check if offset exists
     */
    public function offsetExists(mixed $offset): bool
    {
        return $this->collection->offsetExists($offset);
    }

    /**
     * ArrayAccess support - get Event at offset
     */
    public function offsetGet(mixed $offset): ?EntityEvent
    {
        $entity = $this->collection->offsetGet($offset);
        return $entity instanceof EntityEvent ? $entity : null;
    }

    /**
     * ArrayAccess support - set Event at offset
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($value instanceof EntityEvent) {
            $this->collection->offsetSet($offset, $value);
        }
    }

    /**
     * ArrayAccess support - unset Event at offset
     */
    public function offsetUnset(mixed $offset): void
    {
        $this->collection->offsetUnset($offset);
    }
}
