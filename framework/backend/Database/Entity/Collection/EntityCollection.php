<?php

namespace Hilos\Database\Entity\Collection;

use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\DatabaseException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use ArrayAccess;
use Countable;
use Generator;
use IteratorAggregate;

/**
 * Collection of Entity objects.
 *
 * Child classes must define ENTITY_CLASS constant.
 *
 * @template T of Entity
 * @implements ArrayAccess<int|string, T>
 * @implements IteratorAggregate<int|string, T>
 */
class EntityCollection implements ArrayAccess, Countable, IteratorAggregate
{
    /** @var array<int|string, Entity> entities in collection (key => Entity) */
    protected array $entities = [];

    /**
     * Entity class for this collection. Child classes must define:
     *   public const string ENTITY_CLASS = EntityXxx::class;
     *
     * @var class-string<Entity>
     */
    public const string ENTITY_CLASS = '';

    /**
     * Create empty collection.
     *
     * @return static empty collection instance
     */
    public static function empty(): static
    {
        return new static();
    }

    /**
     * Initialize collection with all entities from database.
     *
     * @return static collection with all entities loaded
     * @throws LogicException If ENTITY_CLASS is not defined
     * @throws DatabaseException When SQL execution fails
     */
    public static function initFullDB(): static
    {
        $entityClass = static::ENTITY_CLASS;
        if ($entityClass === '') {
            throw new LogicException('initFullDB must be called on a specific collection class');
        }
        return static::fromEntityCollection($entityClass::getAll());
    }

    /**
     * Initialize empty collection.
     *
     * @return static empty collection instance
     */
    public static function initEmpty(): static
    {
        return static::empty();
    }

    /**
     * Create from EntityCollection.
     *
     * @param EntityCollection $collection source collection
     * @return static new collection with same entities
     */
    public static function fromEntityCollection(EntityCollection $collection): static
    {
        $result = new static();
        foreach ($collection as $key => $entity) {
            $result[$key] = $entity;
        }
        return $result;
    }

    /**
     * Create collection from array of entities.
     *
     * @param array<int|string, Entity> $entities entities (key => Entity)
     * @return static new collection
     */
    public static function fromArray(array $entities): static
    {
        $collection = new static();
        foreach ($entities as $key => $entity) {
            $collection[$key] = $entity;
        }
        return $collection;
    }

    /**
     * Add entity to collection.
     *
     * @param Entity $entity Entity to add
     * @param int|string|null $key Optional key (null = append)
     * @return self For chaining
     */
    public function add(Entity $entity, int|string|null $key = null): self
    {
        $this->store($entity, $key);
        return $this;
    }

    /**
     * Store entity at key, or append it when no key is given.
     *
     * @param Entity $entity Entity to store
     * @param int|string|null $key Storage key (null = append)
     */
    private function store(Entity $entity, int|string|null $key): void
    {
        if ($key === null) {
            $this->entities[] = $entity;
        } else {
            $this->entities[$key] = $entity;
        }
    }

    /**
     * Remove entity from collection by key.
     *
     * @param int|string|null $key Entity key to remove, or null for no-op
     * @return self For chaining
     */
    public function remove(int|string|null $key): self
    {
        if ($key === null) {
            return $this;
        }
        unset($this->entities[$key]);
        return $this;
    }

    /**
     * Get entity by key.
     *
     * @param int|string|null $key Entity key, or null for a missing optional relation key
     * @return ?T Entity or null if not found
     */
    public function get(int|string|null $key): ?Entity
    {
        if ($key === null) {
            return null;
        }
        return $this->entities[$key] ?? null;
    }

    /**
     * Check if entity exists at key.
     *
     * @param int|string|null $key Entity key, or null for a missing optional relation key
     * @return bool True if entity exists
     */
    public function has(int|string|null $key): bool
    {
        if ($key === null) {
            return false;
        }
        return isset($this->entities[$key]);
    }

    /**
     * Get all entities as array.
     *
     * @return array<int|string, Entity> Entities (key => Entity)
     */
    public function toArray(): array
    {
        return $this->entities;
    }

    /**
     * Get first entity in collection.
     *
     * @return ?T First entity or null if empty
     */
    public function first(): ?Entity
    {
        if (empty($this->entities)) {
            return null;
        }
        return reset($this->entities);
    }

    /**
     * Get last entity in collection.
     *
     * @return ?T Last entity or null if empty
     */
    public function last(): ?Entity
    {
        if (empty($this->entities)) {
            return null;
        }
        return end($this->entities);
    }

    /**
     * Map collection via callback.
     *
     * @param callable(Entity): mixed $callback Callback per entity
     * @return array<int|string, mixed> Mapped values (keys preserved from entities)
     */
    public function map(callable $callback): array
    {
        return array_map($callback, $this->entities);
    }

    /**
     * Check if offset exists (ArrayAccess).
     *
     * @param mixed $offset Entity key, or null for a missing optional relation key
     * @return bool True if key exists
     */
    public function offsetExists(mixed $offset): bool
    {
        if ($offset === null) {
            return false;
        }
        return isset($this->entities[$offset]);
    }

    /**
     * Get entity at offset (ArrayAccess).
     *
     * @param mixed $offset Entity key, or null for a missing optional relation key
     * @return ?T Entity or null if not found
     */
    public function offsetGet(mixed $offset): ?Entity
    {
        if ($offset === null) {
            return null;
        }
        return $this->entities[$offset] ?? null;
    }

    /**
     * Set entity at offset (ArrayAccess).
     *
     * @param mixed $offset Entity key (null to append)
     * @param mixed $value Entity instance
     * @throws InvalidArgumentException If value is not Entity instance
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (!($value instanceof Entity)) {
            throw new InvalidArgumentException("Value must be instance of Entity");
        }

        $entityClass = static::ENTITY_CLASS;
        if ($entityClass !== '' && !($value instanceof $entityClass)) {
            return;
        }

        $this->store($value, $offset);
    }

    /**
     * Unset entity at offset (ArrayAccess).
     *
     * @param mixed $offset Entity key to remove, or null for no-op
     */
    public function offsetUnset(mixed $offset): void
    {
        if ($offset === null) {
            return;
        }
        unset($this->entities[$offset]);
    }

    /**
     * Get collection size (Countable).
     *
     * @return int Number of entities
     */
    public function count(): int
    {
        return count($this->entities);
    }

    /**
     * List the keys currently stored, in insertion order.
     *
     * @return list<int|string> Entity keys
     */
    public function keys(): array
    {
        return array_keys($this->entities);
    }

    /**
     * Walk the entities over a snapshot of the keys taken when the walk starts (IteratorAggregate).
     *
     * Each walk gets its own generator, so a nested foreach over the same collection does not
     * disturb the outer one. A key removed after the snapshot was taken is skipped rather than
     * answered as null, and an entity added during the walk is not seen.
     *
     * @return Generator<int|string, T> Entity key => Entity
     */
    public function getIterator(): Generator
    {
        foreach ($this->keys() as $key) {
            $entity = $this->entities[$key] ?? null;
            if ($entity === null) {
                continue;
            }
            yield $key => $entity;
        }
    }
}
