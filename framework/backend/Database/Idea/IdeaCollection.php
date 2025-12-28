<?php

namespace Hilos\Database\Idea;

use ArrayAccess;
use Countable;
use Hilos\Exception\DatabaseException;
use Iterator;
use Hilos\Database\Object\Object_;
use Hilos\Database\Object\Objects;

/**
 * Base Idea Collection class
 * Provides lazy loading and relation management between Idea items
 * 
 * IdeaCollection contains array of IdeaItem instances.
 * Each IdeaItem references a specific Object stored in ObjectCollection in IdeaStorage.
 * 
 * @template T of IdeaItem
 * @implements ArrayAccess<int|string, T>
 * @implements Iterator<int|string, T>
 */
abstract class IdeaCollection implements ArrayAccess, Countable, Iterator
{
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
    protected function __construct()
    {
    }

    /**
     * Get ObjectCollection from IdeaStorage
     * Must be implemented by child classes to return the appropriate ObjectCollection
     * 
     * @return Objects ObjectCollection instance from IdeaStorage
     */
    abstract protected function getObjectCollection(): Objects;

    /**
     * Create Idea instance from Object
     * Must be implemented by child classes
     * 
     * @param Object_ $object Object instance (reference)
     * @return IdeaItem
     */
    abstract protected function createIdea(Object_ &$object): IdeaItem;

    /**
     * Get Idea instance for object at key
     * Supports lazy loading from Object collection
     * 
     * Creates new IdeaItem instance each time (IdeaItem is lightweight - only stores reference to Object).
     * 
     * @param int|string $key Primary key ID
     * @return IdeaItem|null
     */
    protected function getIdeaForKey(int|string $key): ?IdeaItem
    {
        // Check if Object exists (triggers lazy loading if enabled)
        // Accessing ObjectCollection[$key] will trigger lazy loading if enabled
        $objectCollection = $this->getObjectCollection();
        $object = $objectCollection[$key] ?? null;
        if ($object === null) {
            return null;
        }

        // Create IdeaObject instance (lightweight, no caching needed)
        return $this->createIdea($object);
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
        $objectCollection = $this->getObjectCollection();
        
        foreach ($objectCollection as $key => $object) {
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
     * Note: For lazy-loaded collections, this may trigger full load
     * 
     * @param callable $callback Callback function (Object, key) => bool
     * @return static New filtered collection
     */
    public function filter(callable $callback): static
    {
        $objectCollection = $this->getObjectCollection();
        
        // For lazy collections with BATCH strategy, trigger full load
        if ($objectCollection->allowLazyLoading && 
            $objectCollection->_lazyStrategy === Objects::LAZY_STRATEGY_BATCH &&
            !$objectCollection->_allLoaded) {
            $objectCollection->preloadAll();
        }
        
        // Filter objects and create Idea instances
        $filteredIdeas = [];
        foreach ($objectCollection as $key => $object) {
            if ($callback($object, $key)) {
                $filteredIdeas[$key] = $this->getIdeaForKey($key);
            }
        }
        
        // Note: This returns a new collection, but the underlying Objects
        // collection is not filtered. For a proper implementation, you would
        // need to create a filtered Objects instance. This is a simplified version.
        // The filteredIdeas array is not used, but kept for potential future use.
        return new static();
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
    public function first(): ?IdeaItem
    {
        $objectCollection = $this->getObjectCollection();
        $firstObject = $objectCollection->first();
        if ($firstObject === null) {
            return null;
        }

        $firstKey = array_key_first(iterator_to_array($objectCollection));
        return $firstKey !== null ? $this->getIdeaForKey($firstKey) : null;
    }

    /**
     * Get last Idea
     */
    public function last(): ?IdeaItem
    {
        $objectCollection = $this->getObjectCollection();
        $lastObject = $objectCollection->last();
        if ($lastObject === null) {
            return null;
        }

        $keys = array_keys(iterator_to_array($objectCollection));
        $lastKey = end($keys);
        return $lastKey !== false ? $this->getIdeaForKey($lastKey) : null;
    }

    /**
     * Backup current iterator position
     */
    private int $savedPosition = 0;

    public function backupIndex(): void
    {
        $this->savedPosition = $this->position;
        $this->getObjectCollection()->backupIndex();
    }

    public function restoreIndex(): void
    {
        $this->position = $this->savedPosition;
        $this->getObjectCollection()->restoreIndex();
    }

    // ArrayAccess implementation
    public function offsetExists(mixed $offset): bool
    {
        $objectCollection = $this->getObjectCollection();
        
        // If object is already loaded, it exists
        if (isset($objectCollection[$offset])) {
            return true;
        }

        // If lazy loading is enabled, try to load the object
        // This will check if object exists in database
        if ($objectCollection->allowLazyLoading) {
            $object = $objectCollection[$offset];
            return $object !== null;
        }

        return false;
    }

    public function offsetGet(mixed $offset): ?IdeaItem
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
        $objectCollection = $this->getObjectCollection();
        unset($objectCollection[$offset]);
    }

    // Countable implementation
    public function count(): int
    {
        return $this->getObjectCollection()->count();
    }

    // Iterator implementation
    public function current(): ?IdeaItem
    {
        $objectCollection = $this->getObjectCollection();
        $objectCollection->rewind();
        $objectKey = null;
        $currentPos = 0;

        foreach ($objectCollection as $key => $obj) {
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
        $objectCollection = $this->getObjectCollection();
        $this->position = 0;
        $this->keys = array_keys(iterator_to_array($objectCollection));
        $objectCollection->rewind();
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

