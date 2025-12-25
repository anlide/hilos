<?php

namespace Hilos\Database\Idea;

/**
 * Base class for individual Idea objects
 * 
 * Idea objects are read-only wrappers around Object instances
 * They provide high-level access to data with lazy loading support for relationships
 * 
 * @property-read mixed $id Primary key value
 */
abstract class IdeaObject
{
    /**
     * Cache for related Idea collections
     * 
     * @var array<string, IdeaCollection>
     */
    private array $_relatedCache = [];

    /**
     * Protected constructor - use static factory methods in child classes
     */
    protected function __construct()
    {
    }

    /**
     * Check if related collection is cached
     * 
     * @param string $name Relationship name
     * @return bool
     */
    protected function hasRelatedCache(string $name): bool
    {
        return isset($this->_relatedCache[$name]);
    }

    /**
     * Get cached related collection
     * 
     * @param string $name Relationship name
     * @return IdeaCollection|null
     */
    protected function getCachedRelated(string $name): ?IdeaCollection
    {
        return $this->_relatedCache[$name] ?? null;
    }

    /**
     * Set cached related collection
     * 
     * @param string $name Relationship name
     * @param IdeaCollection $collection Related collection
     */
    protected function setCachedRelated(string $name, IdeaCollection $collection): void
    {
        $this->_relatedCache[$name] = $collection;
    }

    /**
     * Clear all cached relationships
     */
    public function clearRelatedCache(): void
    {
        $this->_relatedCache = [];
    }

    /**
     * Convert to array
     * Must be implemented by child classes
     * 
     * @param bool $withId Include ID fields
     * @param bool $idAsIndex Use ID as array index
     * @param bool $withBridges Include bridge/junction table data
     * @param bool $withCalculation Include calculated fields
     * @return array
     */
    abstract public function toArray(bool $withId = true, bool $idAsIndex = true, bool $withBridges = false, bool $withCalculation = false): array;
}

