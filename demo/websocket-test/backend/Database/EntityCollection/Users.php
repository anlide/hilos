<?php

namespace Demo\WebSocketTest\Database\EntityCollection;

use Demo\WebSocketTest\Database\Entity\User as EntityUser;
use Hilos\Database\Entity\EntityCollection;
use Hilos\Exception\DatabaseException;

/**
 * Users Entity Collection
 * Typed wrapper around EntityCollection for User entities
 *
 * This is a convenience class that provides type safety and convenience methods
 * while using EntityCollection internally.
 */
final class Users
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
     * Initialize collection with all User entities from database
     *
     * @return self
     * @throws DatabaseException
     */
    public static function initFullDB(): self
    {
        $entityCollection = EntityUser::getAll();
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
     * Get User entity by key
     *
     * @param int|string $key
     * @return ?EntityUser
     */
    public function get(int|string $key): ?EntityUser
    {
        $entity = $this->collection->get($key);
        return $entity instanceof EntityUser ? $entity : null;
    }

    /**
     * Get first User entity
     *
     * @return ?EntityUser
     */
    public function first(): ?EntityUser
    {
        $entity = $this->collection->first();
        return $entity instanceof EntityUser ? $entity : null;
    }

    /**
     * Get last User entity
     *
     * @return ?EntityUser
     */
    public function last(): ?EntityUser
    {
        $entity = $this->collection->last();
        return $entity instanceof EntityUser ? $entity : null;
    }

    /**
     * Get count of User entities
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
     * @param callable(EntityUser): bool $callback
     * @return self
     */
    public function filter(callable $callback): self
    {
        $filtered = $this->collection->filter(function($entity) use ($callback) {
            return $entity instanceof EntityUser && $callback($entity);
        });
        return new self($filtered);
    }

    /**
     * Convert to array
     *
     * @return EntityUser[]
     */
    public function toArray(): array
    {
        $result = [];
        foreach ($this->collection as $entity) {
            if ($entity instanceof EntityUser) {
                $result[] = $entity;
            }
        }
        return $result;
    }

    /**
     * Iterator support - get current User
     *
     * @return ?EntityUser
     */
    public function current(): ?EntityUser
    {
        $entity = $this->collection->current();
        return $entity instanceof EntityUser ? $entity : null;
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
     * ArrayAccess support - get User at offset
     */
    public function offsetGet(mixed $offset): ?EntityUser
    {
        $entity = $this->collection->offsetGet($offset);
        return $entity instanceof EntityUser ? $entity : null;
    }

    /**
     * ArrayAccess support - set User at offset
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($value instanceof EntityUser) {
            $this->collection->offsetSet($offset, $value);
        }
    }

    /**
     * ArrayAccess support - unset User at offset
     */
    public function offsetUnset(mixed $offset): void
    {
        $this->collection->offsetUnset($offset);
    }
}
