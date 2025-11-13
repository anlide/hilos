<?php

namespace Hilos\Database\Idea;

use ArrayAccess;
use Countable;
use Hilos\Exception\DatabaseException;
use Iterator;
use Hilos\Database\Object\ObjectCollection;

/**
 * Base Idea Collection class
 * Provides lazy loading and relation management between Idea objects
 * 
 * @template T of Idea
 * @implements ArrayAccess<int|string, T>
 * @implements Iterator<int|string, T>
 */
abstract class IdeaCollection implements ArrayAccess, Countable, Iterator
{
    /**
     * Object collection that backs this Idea collection
     */
    protected ObjectCollection $objects;

    /**
     * Cached Idea instances
     * 
     * @var array<int|string, Idea>
     */
    private array $ideaCache = [];

    /**
     * Current iterator position
     */
    private int $position = 0;

    /**
     * Iterator keys
     * 
     * @var array<int|string>
     */
    private array $keys = [];

    /**
     * Constructor - should be called by child classes
     */
    protected function __construct(ObjectCollection &$objects)
    {
        $this->objects = &$objects;
    }

    /**
     * Convert Object to Idea instance
     * Must be implemented by child classes
     */
    abstract protected function objectToIdea(mixed $object): Idea;

    /**
     * Clear Idea cache
     */
    public function clearCache(): void
    {
        $this->ideaCache = [];
    }

    /**
     * Get Idea instance for object at key
     */
    protected function getIdeaForKey(int|string $key): ?Idea
    {
        if (!isset($this->objects[$key])) {
            return null;
        }

        if (!isset($this->ideaCache[$key])) {
            $this->ideaCache[$key] = $this->objectToIdea($this->objects[$key]);
        }

        return $this->ideaCache[$key];
    }

    /**
     * Convert to array
     * 
     * @param bool $withId Include ID fields
     * @param bool $idAsIndex Use ID as array index
     * @param bool $withBridges Include bridge/junction table data
     * @param bool $withCalculation Include calculated fields
     */
    public function toArray(bool $withId = true, bool $idAsIndex = true, bool $withBridges = false, bool $withCalculation = false): array
    {
        $result = [];
        
        foreach ($this->objects as $key => $object) {
            $idea = $this->getIdeaForKey($key);
            if ($idea !== null) {
                $data = $idea->toArray($withId, $idAsIndex, $withBridges, $withCalculation);
                if ($idAsIndex) {
                    $result[$key] = $data;
                } else {
                    $result[] = $data;
                }
            }
        }

        return $result;
    }

    /**
     * Filter collection by callback
     */
    public function filter(callable $callback): static
    {
        $filtered = $this->objects->filter($callback);
        return new static($filtered);
    }

    /**
     * Map collection
     */
    public function map(callable $callback): array
    {
        $result = [];
        foreach ($this as $key => $idea) {
            $result[$key] = $callback($idea, $key);
        }
        return $result;
    }

    /**
     * Get first Idea
     */
    public function first(): ?Idea
    {
        $firstObject = $this->objects->first();
        if ($firstObject === null) {
            return null;
        }

        $firstKey = array_key_first(iterator_to_array($this->objects));
        return $this->getIdeaForKey($firstKey);
    }

    /**
     * Get last Idea
     */
    public function last(): ?Idea
    {
        $lastObject = $this->objects->last();
        if ($lastObject === null) {
            return null;
        }

        $keys = array_keys(iterator_to_array($this->objects));
        $lastKey = end($keys);
        return $this->getIdeaForKey($lastKey);
    }

    /**
     * Backup current iterator position
     */
    private int $savedPosition = 0;

    public function backupIndex(): void
    {
        $this->savedPosition = $this->position;
        $this->objects->backupIndex();
    }

    public function restoreIndex(): void
    {
        $this->position = $this->savedPosition;
        $this->objects->restoreIndex();
    }

    // ArrayAccess implementation
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->objects[$offset]);
    }

    public function offsetGet(mixed $offset): ?Idea
    {
        return $this->getIdeaForKey($offset);
    }

    /**
     * @throws DatabaseException
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new DatabaseException("Cannot directly set Idea instances in collection");
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->objects[$offset]);
        unset($this->ideaCache[$offset]);
    }

    // Countable implementation
    public function count(): int
    {
        return $this->objects->count();
    }

    // Iterator implementation
    public function current(): ?Idea
    {
        $this->objects->rewind();
        $objectKey = null;
        $currentPos = 0;

        foreach ($this->objects as $key => $obj) {
            if ($currentPos === $this->position) {
                $objectKey = $key;
                break;
            }
            $currentPos++;
        }

        return $objectKey !== null ? $this->getIdeaForKey($objectKey) : null;
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
        $this->keys = array_keys(iterator_to_array($this->objects));
        $this->objects->rewind();
    }

    public function valid(): bool
    {
        return $this->position < count($this->keys);
    }

    /**
     * Debug info
     */
    public function __debugInfo(): array
    {
        return $this->toArray();
    }
}

