<?php

namespace Hilos\Database\Object\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Database\Object\Item\Object_;
use ArrayAccess;
use Countable;
use Generator;
use IteratorAggregate;

/**
 * Collection of Object_ instances.
 *
 * @template T of Object_
 * @implements ArrayAccess<int|string, T>
 * @implements IteratorAggregate<int|string, T>
 */
class ObjectCollection implements ArrayAccess, Countable, IteratorAggregate
{
    /** @var array<int|string, Object_> Objects keyed by int or string */
    private array $objects = [];

    /**
     * Create empty collection.
     *
     * @return self empty collection instance
     */
    public static function empty(): self
    {
        return new self();
    }

    /**
     * Create collection from array of objects.
     *
     * @param array<int|string, Object_> $objects Objects keyed by int or string
     * @return self Collection instance
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
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
        $this->store($object, $key);
        return $this;
    }

    /**
     * Store an object at the given key, or append it when no key is given.
     *
     * @param Object_ $object Object to store
     * @param int|string|null $key Target key, or null to append
     */
    private function store(Object_ $object, int|string|null $key): void
    {
        if ($key === null) {
            $this->objects[] = $object;
        } else {
            $this->objects[$key] = $object;
        }
    }

    /**
     * Remove object from collection.
     *
     * @param int|string|null $key Key of object to remove, or null for no-op
     * @return self This collection for chaining
     */
    public function remove(int|string|null $key): self
    {
        if ($key === null) {
            return $this;
        }
        unset($this->objects[$key]);
        return $this;
    }

    /**
     * Get object by key.
     *
     * @param int|string|null $key Key of object, or null for a missing optional relation key
     * @return ?Object_ Object or null if not found
     */
    public function get(int|string|null $key): ?Object_
    {
        if ($key === null) {
            return null;
        }
        return $this->objects[$key] ?? null;
    }

    /**
     * Check if object exists at key.
     *
     * @param int|string|null $key Key to check, or null for a missing optional relation key
     * @return bool True if object exists
     */
    public function has(int|string|null $key): bool
    {
        if ($key === null) {
            return false;
        }
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
     * @param callable $callback Filter predicate (receives Object_, returns bool)
     * @return self New filtered collection
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     */
    public function filter(callable $callback): self
    {
        return self::fromArray(array_filter($this->objects, $callback));
    }

    /**
     * Map collection by callback.
     *
     * @param callable $callback Map function (receives Object_, returns mixed)
     * @return array<int|string, mixed> Mapped values
     */
    public function map(callable $callback): array
    {
        return array_map($callback, $this->objects);
    }

    /**
     * Check if offset exists (ArrayAccess).
     *
     * @param mixed $offset Offset to check, or null for a missing optional relation key
     * @return bool True if offset exists
     */
    public function offsetExists(mixed $offset): bool
    {
        if ($offset === null) {
            return false;
        }
        return isset($this->objects[$offset]);
    }

    /**
     * Get object at offset (ArrayAccess).
     *
     * @param mixed $offset Offset to get, or null for a missing optional relation key
     * @return ?Object_ Object or null if not found
     */
    public function offsetGet(mixed $offset): ?Object_
    {
        if ($offset === null) {
            return null;
        }
        return $this->objects[$offset] ?? null;
    }

    /**
     * Set object at offset (ArrayAccess).
     *
     * @param mixed $offset Offset (null = append)
     * @param mixed $value Object_ instance
     * @throws InvalidArgumentException If value is not Object_
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (!($value instanceof Object_)) {
            throw new InvalidArgumentException("Value must be instance of Object_");
        }

        $this->store($value, $offset);
    }

    /**
     * Unset object at offset (ArrayAccess).
     *
     * @param mixed $offset Offset to unset, or null for no-op
     */
    public function offsetUnset(mixed $offset): void
    {
        if ($offset === null) {
            return;
        }
        unset($this->objects[$offset]);
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
     * List the keys currently stored, in insertion order.
     *
     * @return list<int|string> Object keys
     */
    public function keys(): array
    {
        return array_keys($this->objects);
    }

    /**
     * Walk the objects over a snapshot of the keys taken when the walk starts (IteratorAggregate).
     *
     * Each walk gets its own generator, so a nested foreach over the same collection does not
     * disturb the outer one. A key removed after the snapshot was taken is skipped rather than
     * answered as null, and an object added during the walk is not seen.
     *
     * @return Generator<int|string, Object_> Object key => Object_
     */
    public function getIterator(): Generator
    {
        foreach ($this->keys() as $key) {
            $object = $this->objects[$key] ?? null;
            if ($object === null) {
                continue;
            }
            yield $key => $object;
        }
    }
}
