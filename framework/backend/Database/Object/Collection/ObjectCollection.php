<?php

namespace Hilos\Database\Object\Collection;

use Hilos\Database\Object\Item\Object_;
use ArrayAccess;
use Countable;
use Iterator;

/**
 * Collection of Object_ instances
 *
 * @template T of Object_
 * @implements ArrayAccess<int|string, T>
 * @implements Iterator<int|string, T>
 */
class ObjectCollection implements ArrayAccess, Countable, Iterator
{
    /** @var array<int|string, Object_> */
    private array $objects = [];

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
     * Create collection from array of objects
     *
     * @param Object_[] $objects
     */
    public static function fromArray(array $objects): self
    {
        $collection = new self();
        foreach ($objects as $key => $object) {
            $collection[$key] = $object;
        }
        return $collection;
    }

    /**
     * Add object to collection
     */
    public function add(Object_ $object, int|string|null $key = null): self
    {
        if ($key === null) {
            $this->objects[] = $object;
            $this->keys = array_keys($this->objects);
        } else {
            $this->objects[$key] = $object;
            if (!in_array($key, $this->keys, true)) {
                $this->keys[] = $key;
            }
        }
        return $this;
    }

    /**
     * Remove object from collection
     */
    public function remove(int|string $key): self
    {
        if (isset($this->objects[$key])) {
            unset($this->objects[$key]);
            $this->keys = array_keys($this->objects);
        }
        return $this;
    }

    /**
     * Get object by key
     */
    public function get(int|string $key): ?Object_
    {
        return $this->objects[$key] ?? null;
    }

    /**
     * Check if object exists at key
     */
    public function has(int|string $key): bool
    {
        return isset($this->objects[$key]);
    }

    /**
     * Get all objects as array
     *
     * @return Object_[]
     */
    public function toArray(): array
    {
        return $this->objects;
    }

    /**
     * Get first object
     */
    public function first(): ?Object_
    {
        if (empty($this->objects)) {
            return null;
        }
        return reset($this->objects);
    }

    /**
     * Get last object
     */
    public function last(): ?Object_
    {
        if (empty($this->objects)) {
            return null;
        }
        return end($this->objects);
    }

    /**
     * Filter collection
     */
    public function filter(callable $callback): self
    {
        return self::fromArray(array_filter($this->objects, $callback));
    }

    /**
     * Map collection
     */
    public function map(callable $callback): array
    {
        return array_map($callback, $this->objects);
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
        return isset($this->objects[$offset]);
    }

    public function offsetGet(mixed $offset): ?Object_
    {
        return $this->objects[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (!($value instanceof Object_)) {
            throw new \InvalidArgumentException("Value must be instance of Object_");
        }

        if ($offset === null) {
            $this->objects[] = $value;
            $this->keys = array_keys($this->objects);
        } else {
            $this->objects[$offset] = $value;
            if (!in_array($offset, $this->keys, true)) {
                $this->keys[] = $offset;
            }
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->objects[$offset]);
        $this->keys = array_keys($this->objects);
    }

    // Countable implementation
    public function count(): int
    {
        return count($this->objects);
    }

    // Iterator implementation
    public function current(): ?Object_
    {
        $key = $this->keys[$this->position] ?? null;
        return $key !== null ? $this->objects[$key] : null;
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
        $this->keys = array_keys($this->objects);
    }

    public function valid(): bool
    {
        return isset($this->keys[$this->position]);
    }
}
