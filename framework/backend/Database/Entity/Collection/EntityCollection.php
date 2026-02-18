<?php

namespace Hilos\Database\Entity\Collection;

use Hilos\Database\Entity\Item\Entity;
use ArrayAccess;
use Countable;
use Iterator;

/**
 * Collection of Entity objects
 *
 * Child classes must define ENTITY_CLASS constant.
 *
 * @template T of Entity
 * @implements ArrayAccess<int|string, T>
 * @implements Iterator<int|string, T>
 */
class EntityCollection implements ArrayAccess, Countable, Iterator
{
    /** @var array<int|string, Entity> */
    protected array $entities = [];

    /** @var array<int|string> */
    protected array $keys = [];

    /** Current iterator position */
    private int $position = 0;

    /** Backup of iterator position for backupIndex/restoreIndex */
    private int $savedPosition = 0;

    /**
     * Entity class for this collection. Child classes must define:
     *   public const string ENTITY_CLASS = EntityXxx::class;
     *
     * @var class-string<Entity>
     */
    public const string ENTITY_CLASS = '';

    /**
     * Create empty collection
     */
    public static function empty(): static
    {
        return new static();
    }

    /**
     * Initialize collection with all entities from database
     */
    public static function initFullDB(): static
    {
        $entityClass = static::ENTITY_CLASS;
        if ($entityClass === '') {
            throw new \LogicException('initFullDB must be called on a specific collection class');
        }
        return static::fromEntityCollection($entityClass::getAll());
    }

    /**
     * Initialize empty collection
     */
    public static function initEmpty(): static
    {
        return static::empty();
    }

    /**
     * Create from EntityCollection
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
     * Create collection from array of entities
     *
     * @param Entity[] $entities
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
     * Add entity to collection
     */
    public function add(Entity $entity, int|string|null $key = null): self
    {
        if ($key === null) {
            $this->entities[] = $entity;
            $this->keys = array_keys($this->entities);
        } else {
            $this->entities[$key] = $entity;
            if (!in_array($key, $this->keys, true)) {
                $this->keys[] = $key;
            }
        }
        return $this;
    }

    /**
     * Remove entity from collection
     */
    public function remove(int|string $key): self
    {
        if (isset($this->entities[$key])) {
            unset($this->entities[$key]);
            $this->keys = array_keys($this->entities);
        }
        return $this;
    }

    /**
     * Get entity by key
     *
     * @return ?T
     */
    public function get(int|string $key): ?Entity
    {
        return $this->entities[$key] ?? null;
    }

    /**
     * Check if entity exists at key
     */
    public function has(int|string $key): bool
    {
        return isset($this->entities[$key]);
    }

    /**
     * Get all entities as array
     *
     * @return Entity[]
     */
    public function toArray(): array
    {
        return $this->entities;
    }

    /**
     * Get first entity
     *
     * @return ?T
     */
    public function first(): ?Entity
    {
        if (empty($this->entities)) {
            return null;
        }
        return reset($this->entities);
    }

    /**
     * Get last entity
     *
     * @return ?T
     */
    public function last(): ?Entity
    {
        if (empty($this->entities)) {
            return null;
        }
        return end($this->entities);
    }

    /**
     * Map collection
     */
    public function map(callable $callback): array
    {
        return array_map($callback, $this->entities);
    }

    /**
     * Backup current iterator position
     */
    public function backupIndex(): void
    {
        $this->savedPosition = $this->position;
    }

    /**
     * Restore iterator position from backup
     */
    public function restoreIndex(): void
    {
        $this->position = $this->savedPosition;
    }

    /**
     * Check if offset exists (ArrayAccess)
     */
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->entities[$offset]);
    }

    /**
     * Get entity at offset (ArrayAccess)
     *
     * @return ?T
     */
    public function offsetGet(mixed $offset): ?Entity
    {
        return $this->entities[$offset] ?? null;
    }

    /**
     * Set entity at offset (ArrayAccess)
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (!($value instanceof Entity)) {
            throw new \InvalidArgumentException("Value must be instance of Entity");
        }

        $entityClass = static::ENTITY_CLASS;
        if ($entityClass !== '' && !($value instanceof $entityClass)) {
            return;
        }

        if ($offset === null) {
            $this->entities[] = $value;
            $this->keys = array_keys($this->entities);
        } else {
            $this->entities[$offset] = $value;
            if (!in_array($offset, $this->keys, true)) {
                $this->keys[] = $offset;
            }
        }
    }

    /**
     * Unset entity at offset (ArrayAccess)
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->entities[$offset]);
        $this->keys = array_keys($this->entities);
    }

    /**
     * Get collection size (Countable)
     */
    public function count(): int
    {
        return count($this->entities);
    }

    /**
     * Get current entity (Iterator)
     *
     * @return ?T
     */
    public function current(): ?Entity
    {
        $key = $this->keys[$this->position] ?? null;
        return $key !== null ? $this->entities[$key] : null;
    }

    /**
     * Get current key (Iterator)
     */
    public function key(): mixed
    {
        return $this->keys[$this->position] ?? null;
    }

    /**
     * Move to next element (Iterator)
     */
    public function next(): void
    {
        ++$this->position;
    }

    /**
     * Rewind to first element (Iterator)
     */
    public function rewind(): void
    {
        $this->position = 0;
        $this->keys = array_keys($this->entities);
    }

    /**
     * Check if current position is valid (Iterator)
     */
    public function valid(): bool
    {
        return isset($this->keys[$this->position]);
    }
}
