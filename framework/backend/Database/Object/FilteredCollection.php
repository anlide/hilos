<?php

namespace Hilos\Database\Object;

use Generator;
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
     * List the filtered keys, in the order the filter produced them.
     *
     * Nothing is loaded here: a filtered collection holds references to objects the source has
     * already produced, so the lazy strategies the parent honours have no work left to do.
     *
     * @return list<int|string> Filtered object keys
     */
    public function keys(): array
    {
        return array_keys($this->filteredObjects);
    }

    /**
     * Walk the filtered objects over a snapshot of the keys taken when the walk starts.
     *
     * @return Generator<int|string, Object_> Object key => Object_
     */
    public function getIterator(): Generator
    {
        foreach ($this->keys() as $key) {
            $object = $this->filteredObjects[$key] ?? null;
            if ($object === null) {
                continue;
            }
            yield $key => $object;
        }
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
