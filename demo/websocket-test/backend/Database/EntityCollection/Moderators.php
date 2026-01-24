<?php

namespace Demo\WebSocketTest\Database\EntityCollection;

use Demo\WebSocketTest\Database\Entity\Moderator as EntityModerator;
use Hilos\Database\Entity\EntityCollection;
use Hilos\Exception\DatabaseException;

/**
 * Moderators Entity Collection
 * Typed wrapper around EntityCollection for Moderator entities
 *
 * This is a convenience class that provides type safety and convenience methods
 * while using EntityCollection internally.
 */
final class Moderators
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
     * Initialize collection with all Moderator entities from database
     *
     * @return self
     * @throws DatabaseException
     */
    public static function initFullDB(): self
    {
        $entityCollection = EntityModerator::getAll();
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
     * Get Moderator entity by key
     *
     * @param int|string $key
     * @return ?EntityModerator
     */
    public function get(int|string $key): ?EntityModerator
    {
        $entity = $this->collection->get($key);
        return $entity instanceof EntityModerator ? $entity : null;
    }

    /**
     * Get first Moderator entity
     *
     * @return ?EntityModerator
     */
    public function first(): ?EntityModerator
    {
        $entity = $this->collection->first();
        return $entity instanceof EntityModerator ? $entity : null;
    }

    /**
     * Get last Moderator entity
     *
     * @return ?EntityModerator
     */
    public function last(): ?EntityModerator
    {
        $entity = $this->collection->last();
        return $entity instanceof EntityModerator ? $entity : null;
    }

    /**
     * Get count of Moderator entities
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
     * @param callable(EntityModerator): bool $callback
     * @return self
     */
    public function filter(callable $callback): self
    {
        $filtered = $this->collection->filter(function($entity) use ($callback) {
            return $entity instanceof EntityModerator && $callback($entity);
        });
        return new self($filtered);
    }

    /**
     * Convert to array
     *
     * @return EntityModerator[]
     */
    public function toArray(): array
    {
        $result = [];
        foreach ($this->collection as $entity) {
            if ($entity instanceof EntityModerator) {
                $result[] = $entity;
            }
        }
        return $result;
    }

    /**
     * Iterator support - get current Moderator
     *
     * @return ?EntityModerator
     */
    public function current(): ?EntityModerator
    {
        $entity = $this->collection->current();
        return $entity instanceof EntityModerator ? $entity : null;
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
     * ArrayAccess support - get Moderator at offset
     */
    public function offsetGet(mixed $offset): ?EntityModerator
    {
        $entity = $this->collection->offsetGet($offset);
        return $entity instanceof EntityModerator ? $entity : null;
    }

    /**
     * ArrayAccess support - set Moderator at offset
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($value instanceof EntityModerator) {
            $this->collection->offsetSet($offset, $value);
        }
    }

    /**
     * ArrayAccess support - unset Moderator at offset
     */
    public function offsetUnset(mixed $offset): void
    {
        $this->collection->offsetUnset($offset);
    }
}
