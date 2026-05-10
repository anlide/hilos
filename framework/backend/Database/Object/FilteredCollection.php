<?php

namespace Hilos\Database\Object;

use Hilos\Core\Exception\InvalidStateException;
use Hilos\Database\Object\Item\Object_;

/**
 * Filtered collection wrapper.
 *
 * Stores only references to objects from source collection.
 * No data duplication - all objects stored in source collection.
 */
class FilteredCollection extends Objects
{
    /** @var Objects source collection */
    private Objects $sourceCollection;

    /** @var array<int|string, Object_> key => Object_ references to objects from sourceCollection */
    private array $filteredObjects;

    /**
     * Creates filtered collection from source with pre-filtered objects.
     *
     * @param Objects $source Source collection
     * @param array<int|string, Object_> $filteredObjects References to objects from source (key => Object_)
     */
    public function __construct(Objects $source, array $filteredObjects)
    {
        parent::__construct();
        $this->sourceCollection = $source;
        $this->filteredObjects = $filteredObjects;
    }

    /**
     * Get ObjectCollection from storage.
     *
     * Returns source collection.
     *
     * @return ?Objects Source collection
     */
    protected function getObjectCollection(): ?Objects
    {
        return $this->sourceCollection;
    }

    /**
     * Get table name from Entity.
     *
     * Delegates to source collection.
     *
     * @return string Table name
     */
    public function getTableName(): string
    {
        return $this->sourceCollection->getTableName();
    }

    /**
     * Get collection key.
     *
     * Delegates to source collection.
     *
     * @return string Collection key
     */
    public function getCollectionKey(): string
    {
        return $this->sourceCollection->getCollectionKey();
    }

    // Override methods to work with filtered objects only

    /**
     * Get object at offset.
     *
     * @param string|int|null $offset Object key, or null for a missing optional relation key
     * @return ?Object_ Object or null if not found
     */
    public function offsetGet($offset): ?Object_
    {
        if ($offset === null) {
            return null;
        }
        return $this->filteredObjects[$offset] ?? null;
    }

    /**
     * Check if offset exists in filtered collection.
     *
     * @param string|int|null $offset Object key, or null for a missing optional relation key
     * @return bool True if exists
     */
    public function offsetExists($offset): bool
    {
        if ($offset === null) {
            return false;
        }
        return isset($this->filteredObjects[$offset]);
    }

    /**
     * Remove object at offset from filtered collection.
     *
     * @param string|int|null $offset Object key, or null for no-op
     */
    public function offsetUnset($offset): void
    {
        if ($offset === null) {
            return;
        }
        unset($this->filteredObjects[$offset]);
    }

    /**
     * Get filtered collection size.
     *
     * @return int Number of filtered objects
     */
    public function count(): int
    {
        return count($this->filteredObjects);
    }

    /**
     * Get current object in iteration.
     *
     * @return ?Object_ Current object or null
     */
    public function current(): ?Object_
    {
        $keys = array_keys($this->filteredObjects);
        if (!isset($keys[$this->index])) {
            return null;
        }
        return $this->filteredObjects[$keys[$this->index]];
    }

    /**
     * Get current key in iteration.
     *
     * @return string|int Current key
     */
    public function key(): string|int
    {
        $keys = array_keys($this->filteredObjects);
        return $keys[$this->index] ?? 0;
    }

    /**
     * Advance iterator to next element.
     */
    public function next(): void
    {
        $this->index++;
    }

    /**
     * Check if current position is valid.
     *
     * @return bool True if valid
     */
    public function valid(): bool
    {
        $keys = array_keys($this->filteredObjects);
        return isset($keys[$this->index]);
    }

    /**
     * Reset iterator to first element.
     */
    public function rewind(): void
    {
        $this->index = 0;
    }

    // Abstract methods - must implement but not used in filtered collection

    /**
     * Initialize empty collection (not supported for FilteredCollection).
     *
     * @return static Never returns (throws)
     * @throws InvalidStateException Always, direct init not allowed
     */
    public static function initEmpty(): static
    {
        throw new InvalidStateException("FilteredCollection cannot be initialized directly");
    }

    /**
     * Load all from DB (not supported for FilteredCollection).
     *
     * @throws InvalidStateException Always, direct load not allowed
     */
    public function loadAllFromDB(): void
    {
        throw new InvalidStateException("FilteredCollection cannot load from database directly");
    }

    /**
     * Lazy load object by key (not used in filtered collection).
     *
     * @param int|string|null $key Object key, or null for a missing optional relation key
     * @return ?Object_ Always null
     */
    protected function lazyLoadObject(int|string|null $key): ?Object_
    {
        return null; // Not used in filtered collection
    }

    /**
     * Lazy load count (not used in filtered collection).
     *
     * @return int Always 0
     */
    protected function lazyLoadCount(): int
    {
        return 0; // Not used in filtered collection
    }

    /**
     * Lazy load all (not used in filtered collection).
     */
    protected function lazyLoadAll(): void
    {
        // Not used in filtered collection
    }
}
