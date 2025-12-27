<?php

namespace Demo\WebSocketTest\Database\EntityCollection;

use Demo\WebSocketTest\Database\Entity\UserSetting as EntityUserSetting;
use Hilos\Database\Entity\EntityCollection;
use Hilos\Exception\DatabaseException;

/**
 * UserSettings Entity Collection
 * Typed wrapper around EntityCollection for UserSetting entities
 *
 * This is a convenience class that provides type safety and convenience methods
 * while using EntityCollection internally.
 */
final class UserSettings
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
     * Initialize collection with all UserSetting entities from database
     *
     * @return self
     * @throws DatabaseException
     */
    public static function initFullDB(): self
    {
        $entityCollection = EntityUserSetting::getAll();
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
     * Get UserSetting entity by key
     *
     * @param int|string $key
     * @return EntityUserSetting|null
     */
    public function get(int|string $key): ?EntityUserSetting
    {
        $entity = $this->collection->get($key);
        return $entity instanceof EntityUserSetting ? $entity : null;
    }

    /**
     * Get first UserSetting entity
     *
     * @return EntityUserSetting|null
     */
    public function first(): ?EntityUserSetting
    {
        $entity = $this->collection->first();
        return $entity instanceof EntityUserSetting ? $entity : null;
    }

    /**
     * Get last UserSetting entity
     *
     * @return EntityUserSetting|null
     */
    public function last(): ?EntityUserSetting
    {
        $entity = $this->collection->last();
        return $entity instanceof EntityUserSetting ? $entity : null;
    }

    /**
     * Get count of UserSetting entities
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
     * @param callable(EntityUserSetting): bool $callback
     * @return self
     */
    public function filter(callable $callback): self
    {
        $filtered = $this->collection->filter(function($entity) use ($callback) {
            return $entity instanceof EntityUserSetting && $callback($entity);
        });
        return new self($filtered);
    }

    /**
     * Convert to array
     *
     * @return EntityUserSetting[]
     */
    public function toArray(): array
    {
        $result = [];
        foreach ($this->collection as $entity) {
            if ($entity instanceof EntityUserSetting) {
                $result[] = $entity;
            }
        }
        return $result;
    }

    /**
     * Iterator support - get current UserSetting
     *
     * @return EntityUserSetting|null
     */
    public function current(): ?EntityUserSetting
    {
        $entity = $this->collection->current();
        return $entity instanceof EntityUserSetting ? $entity : null;
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
     * ArrayAccess support - get UserSetting at offset
     */
    public function offsetGet(mixed $offset): ?EntityUserSetting
    {
        $entity = $this->collection->offsetGet($offset);
        return $entity instanceof EntityUserSetting ? $entity : null;
    }

    /**
     * ArrayAccess support - set UserSetting at offset
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($value instanceof EntityUserSetting) {
            $this->collection->offsetSet($offset, $value);
        }
    }

    /**
     * ArrayAccess support - unset UserSetting at offset
     */
    public function offsetUnset(mixed $offset): void
    {
        $this->collection->offsetUnset($offset);
    }
}
