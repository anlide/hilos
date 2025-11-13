<?php

namespace Hilos\Database\Entity;

use ArrayAccess;
use Countable;
use Iterator;

/**
 * Collection of Entity objects
 * 
 * @template T of Entity
 * @implements ArrayAccess<int|string, T>
 * @implements Iterator<int|string, T>
 */
class EntityCollection implements ArrayAccess, Countable, Iterator
{
    /** @var array<int|string, Entity> */
    private array $entities = [];
    
    /** @var array<int|string> */
    private array $keys = [];
    
    private int $position = 0;

    /**
     * Create empty collection
     */
    public static function empty(): self
    {
        return new self();
    }

    /**
     * Create collection from array of entities
     * 
     * @param Entity[] $entities
     */
    public static function fromArray(array $entities): self
    {
        $collection = new self();
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
     */
    public function last(): ?Entity
    {
        if (empty($this->entities)) {
            return null;
        }
        return end($this->entities);
    }

    /**
     * Filter collection
     */
    public function filter(callable $callback): self
    {
        return self::fromArray(array_filter($this->entities, $callback));
    }

    /**
     * Map collection
     */
    public function map(callable $callback): array
    {
        return array_map($callback, $this->entities);
    }

    /**
     * Backup current position
     */
    private int $savedPosition = 0;

    public function backupIndex(): void
    {
        $this->savedPosition = $this->position;
    }

    public function restoreIndex(): void
    {
        $this->position = $this->savedPosition;
    }

    // ArrayAccess implementation
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->entities[$offset]);
    }

    public function offsetGet(mixed $offset): ?Entity
    {
        return $this->entities[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (!($value instanceof Entity)) {
            throw new \InvalidArgumentException("Value must be instance of Entity");
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

    public function offsetUnset(mixed $offset): void
    {
        unset($this->entities[$offset]);
        $this->keys = array_keys($this->entities);
    }

    // Countable implementation
    public function count(): int
    {
        return count($this->entities);
    }

    // Iterator implementation
    public function current(): ?Entity
    {
        $key = $this->keys[$this->position] ?? null;
        return $key !== null ? $this->entities[$key] : null;
    }

    public function key(): mixed
    {
        return $this->keys[$this->position] ?? null;
    }

    public function next(): void
    {
        ++$this->position;
    }

    public function rewind(): void
    {
        $this->position = 0;
        $this->keys = array_keys($this->entities);
    }

    public function valid(): bool
    {
        return isset($this->keys[$this->position]);
    }
}

