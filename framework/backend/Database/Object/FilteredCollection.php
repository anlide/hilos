<?php

namespace Hilos\Database\Object;

use Hilos\Database\Filter\FilterInterface;
use Hilos\Database\Object\Item\Object_;

/**
 * Filtered collection wrapper.
 *
 * Stores only references to objects from source collection.
 * No data duplication - all objects stored in source collection.
 */
class FilteredCollection extends Objects
{
    private Objects $sourceCollection;
    private FilterInterface $filter;
    /** @var Object_[] [key => Object_] - только ссылки на объекты из sourceCollection */
    private array $filteredObjects;

    /**
     * Creates filtered collection from source with filter criteria.
     *
     * @param Objects $source Source collection
     * @param FilterInterface $filter Filter criteria
     * @param array<string, Object_> $filteredObjects References to objects from source (key => Object_)
     */
    public function __construct(Objects $source, FilterInterface $filter, array $filteredObjects)
    {
        $this->sourceCollection = $source;
        $this->filter = $filter;
        $this->filteredObjects = $filteredObjects;
    }

    /**
     * Get ObjectCollection from IdeaStorage.
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

    public function offsetGet($offset): ?Object_
    {
        return $this->filteredObjects[$offset] ?? null;
    }

    public function offsetExists($offset): bool
    {
        return isset($this->filteredObjects[$offset]);
    }

    public function offsetUnset($offset): void
    {
        unset($this->filteredObjects[$offset]);
    }

    public function count(): int
    {
        return count($this->filteredObjects);
    }

    public function current(): ?Object_
    {
        $keys = array_keys($this->filteredObjects);
        if (!isset($keys[$this->index])) {
            return null;
        }
        return $this->filteredObjects[$keys[$this->index]];
    }

    public function key(): string|int
    {
        $keys = array_keys($this->filteredObjects);
        return $keys[$this->index] ?? 0;
    }

    public function next(): void
    {
        $this->index++;
    }

    public function valid(): bool
    {
        $keys = array_keys($this->filteredObjects);
        return isset($keys[$this->index]);
    }

    public function rewind(): void
    {
        $this->index = 0;
    }

    // Abstract methods - must implement but not used in filtered collection
    public static function initEmpty(): static
    {
        throw new \RuntimeException("FilteredCollection cannot be initialized directly");
    }

    public function loadAllFromDB(): void
    {
        throw new \RuntimeException("FilteredCollection cannot load from database directly");
    }

    protected function lazyLoadObject(int|string $key): ?Object_
    {
        return null; // Not used in filtered collection
    }

    protected function lazyLoadCount(): int
    {
        return 0; // Not used in filtered collection
    }

    protected function lazyLoadAll(): void
    {
        // Not used in filtered collection
    }
}

