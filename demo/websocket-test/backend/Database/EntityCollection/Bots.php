<?php

namespace Demo\WebSocketTest\Database\EntityCollection;

use Demo\WebSocketTest\Database\Entity\Bot as EntityBot;
use Hilos\Database\Entity\EntityCollection;
use Hilos\Exception\DatabaseException;

/**
 * Bots Entity Collection
 * Typed wrapper around EntityCollection for Bot entities
 *
 * This is a convenience class that provides type safety and convenience methods
 * while using EntityCollection internally.
 */
final class Bots
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
     * Initialize collection with all Bot entities from database
     *
     * @return self
     * @throws DatabaseException
     */
    public static function initFullDB(): self
    {
        $entityCollection = EntityBot::getAll();
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
     * Get Bot entity by key
     *
     * @param int|string $key
     * @return EntityBot|null
     */
    public function get(int|string $key): ?EntityBot
    {
        $entity = $this->collection->get($key);
        return $entity instanceof EntityBot ? $entity : null;
    }

    /**
     * Get first Bot entity
     *
     * @return EntityBot|null
     */
    public function first(): ?EntityBot
    {
        $entity = $this->collection->first();
        return $entity instanceof EntityBot ? $entity : null;
    }

    /**
     * Get last Bot entity
     *
     * @return EntityBot|null
     */
    public function last(): ?EntityBot
    {
        $entity = $this->collection->last();
        return $entity instanceof EntityBot ? $entity : null;
    }

    /**
     * Get count of Bot entities
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
     * @param callable(EntityBot): bool $callback
     * @return self
     */
    public function filter(callable $callback): self
    {
        $filtered = $this->collection->filter(function($entity) use ($callback) {
            return $entity instanceof EntityBot && $callback($entity);
        });
        return new self($filtered);
    }

    /**
     * Convert to array
     *
     * @return EntityBot[]
     */
    public function toArray(): array
    {
        $result = [];
        foreach ($this->collection as $entity) {
            if ($entity instanceof EntityBot) {
                $result[] = $entity;
            }
        }
        return $result;
    }

    /**
     * Iterator support - get current Bot
     *
     * @return EntityBot|null
     */
    public function current(): ?EntityBot
    {
        $entity = $this->collection->current();
        return $entity instanceof EntityBot ? $entity : null;
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
     * ArrayAccess support - get Bot at offset
     */
    public function offsetGet(mixed $offset): ?EntityBot
    {
        $entity = $this->collection->offsetGet($offset);
        return $entity instanceof EntityBot ? $entity : null;
    }

    /**
     * ArrayAccess support - set Bot at offset
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($value instanceof EntityBot) {
            $this->collection->offsetSet($offset, $value);
        }
    }

    /**
     * ArrayAccess support - unset Bot at offset
     */
    public function offsetUnset(mixed $offset): void
    {
        $this->collection->offsetUnset($offset);
    }
}
