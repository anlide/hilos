<?php

namespace Demo\WebSocketTest\Database\EntityCollection;

use Demo\WebSocketTest\Database\Entity\Message as EntityMessage;
use Hilos\Database\Entity\EntityCollection;
use Hilos\Exception\DatabaseException;

/**
 * Messages Entity Collection
 * Typed wrapper around EntityCollection for Message entities
 *
 * This is a convenience class that provides type safety and convenience methods
 * while using EntityCollection internally.
 */
final class Messages
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
     * Initialize collection with all Message entities from database
     *
     * @return self
     * @throws DatabaseException
     */
    public static function initFullDB(): self
    {
        $entityCollection = EntityMessage::getAll();
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
     * Get Message entity by key
     *
     * @param int|string $key
     * @return EntityMessage|null
     */
    public function get(int|string $key): ?EntityMessage
    {
        $entity = $this->collection->get($key);
        return $entity instanceof EntityMessage ? $entity : null;
    }

    /**
     * Get first Message entity
     *
     * @return EntityMessage|null
     */
    public function first(): ?EntityMessage
    {
        $entity = $this->collection->first();
        return $entity instanceof EntityMessage ? $entity : null;
    }

    /**
     * Get last Message entity
     *
     * @return EntityMessage|null
     */
    public function last(): ?EntityMessage
    {
        $entity = $this->collection->last();
        return $entity instanceof EntityMessage ? $entity : null;
    }

    /**
     * Get count of Message entities
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
     * @param callable(EntityMessage): bool $callback
     * @return self
     */
    public function filter(callable $callback): self
    {
        $filtered = $this->collection->filter(function($entity) use ($callback) {
            return $entity instanceof EntityMessage && $callback($entity);
        });
        return new self($filtered);
    }

    /**
     * Convert to array
     *
     * @return EntityMessage[]
     */
    public function toArray(): array
    {
        $result = [];
        foreach ($this->collection as $entity) {
            if ($entity instanceof EntityMessage) {
                $result[] = $entity;
            }
        }
        return $result;
    }

    /**
     * Iterator support - get current Message
     *
     * @return EntityMessage|null
     */
    public function current(): ?EntityMessage
    {
        $entity = $this->collection->current();
        return $entity instanceof EntityMessage ? $entity : null;
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
     * ArrayAccess support - get Message at offset
     */
    public function offsetGet(mixed $offset): ?EntityMessage
    {
        $entity = $this->collection->offsetGet($offset);
        return $entity instanceof EntityMessage ? $entity : null;
    }

    /**
     * ArrayAccess support - set Message at offset
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($value instanceof EntityMessage) {
            $this->collection->offsetSet($offset, $value);
        }
    }

    /**
     * ArrayAccess support - unset Message at offset
     */
    public function offsetUnset(mixed $offset): void
    {
        $this->collection->offsetUnset($offset);
    }
}
