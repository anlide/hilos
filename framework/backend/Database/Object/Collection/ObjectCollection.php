<?php

namespace Hilos\Database\Object\Collection;

use Hilos\Database\Object\Item\Object_;
use ArrayAccess;
use Countable;
use Iterator;

/**
 * Collection of Object_ instances.
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

    /** @var int Current iterator position */
    private int $position = 0;

    /** @var int Saved position for backup/restore */
    private int $savedPosition = 0;

    /**
     * Create empty collection.
     *
     * @return static empty collection instance
     */
    public static function empty(): self
    {
        return new self();
    }

    /**
     * Create collection from array of objects.
     *
     * @param array<int|string, Object_> $objects Objects keyed by int or string
     * @return static Collection instance
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
     * Add object to collection.
     *
     * @param Object_ $object Object to add
     * @param int|string|null $key Optional key (null = append)
     * @return self This collection for chaining
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
     * Remove object from collection.
     *
     * @param int|string $key Key of object to remove
     * @return self This collection for chaining
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
     * Get object by key.
     *
     * @param int|string $key Key of object
     * @return ?Object_ Object or null if not found
     */
    public function get(int|string $key): ?Object_
    {
        return $this->objects[$key] ?? null;
    }

    /**
     * Check if object exists at key.
     *
     * @param int|string $key Key to check
     * @return bool True if object exists
     */
    public function has(int|string $key): bool
    {
        return isset($this->objects[$key]);
    }

    /**
     * Get all objects as array.
     *
     * @return array<int|string, Object_> Objects keyed by int or string
     */
    public function toArray(): array
    {
        return $this->objects;
    }

    /**
     * Get first object.
     *
     * @return ?Object_ First object or null if empty
     */
    public function first(): ?Object_
    {
        if (empty($this->objects)) {
            return null;
        }
        return reset($this->objects);
    }

    /**
     * Get last object.
     *
     * @return ?Object_ Last object or null if empty
     */
    public function last(): ?Object_
    {
        if (empty($this->objects)) {
            return null;
        }
        return end($this->objects);
    }

    /**
     * Filter collection by callback.
     *
     * @param callable(Object_): bool $callback Filter predicate
     * @return self New filtered collection
     */
    public function filter(callable $callback): self
    {
        return self::fromArray(array_filter($this->objects, $callback));
    }

    /**
     * Map collection by callback.
     *
     * @param callable(Object_): mixed $callback Map function
     * @return array<int|string, mixed> Mapped values
     */
    public function map(callable $callback): array
    {
        return array_map($callback, $this->objects);
    }

    /**
     * Backup current iterator position for later restore.
     */
    public function backupIndex(): void
    {
        $this->savedPosition = $this->position;
    }

    /**
     * Restore iterator position from previous backup.
     */
    public function restoreIndex(): void
    {
        $this->position = $this->savedPosition;
    }

    /**
     * Check if offset exists (ArrayAccess).
     *
     * @param mixed $offset Offset to check
     * @return bool True if offset exists
     */
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->objects[$offset]);
    }

    /**
     * Get object at offset (ArrayAccess).
     *
     * @param mixed $offset Offset to get
     * @return ?Object_ Object or null if not found
     */
    public function offsetGet(mixed $offset): ?Object_
    {
        return $this->objects[$offset] ?? null;
    }

    /**
     * Set object at offset (ArrayAccess).
     *
     * @param mixed $offset Offset (null = append)
     * @param mixed $value Object_ instance
     * @throws \InvalidArgumentException If value is not Object_
     */
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

    /**
     * Unset object at offset (ArrayAccess).
     *
     * @param mixed $offset Offset to unset
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->objects[$offset]);
        $this->keys = array_keys($this->objects);
    }

    /**
     * Get collection count (Countable).
     *
     * @return int Number of objects
     */
    public function count(): int
    {
        return count($this->objects);
    }

    /**
     * Get current element (Iterator).
     *
     * @return ?Object_ Current object or null
     */
    public function current(): ?Object_
    {
        $key = $this->keys[$this->position] ?? null;
        return $key !== null ? $this->objects[$key] : null;
    }

    /**
     * Get current key (Iterator).
     *
     * @return mixed Current key or null
     */
    public function key(): mixed
    {
        return $this->keys[$this->position] ?? null;
    }

    /**
     * Advance to next element (Iterator).
     */
    public function next(): void
    {
        ++$this->position;
    }

    /**
     * Rewind to first element (Iterator).
     */
    public function rewind(): void
    {
        $this->position = 0;
        $this->keys = array_keys($this->objects);
    }

    /**
     * Check if current position is valid (Iterator).
     *
     * @return bool True if current position has element
     */
    public function valid(): bool
    {
        return isset($this->keys[$this->position]);
    }
}
