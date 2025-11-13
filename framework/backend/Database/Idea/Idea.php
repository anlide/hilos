<?php

namespace Hilos\Database\Idea;

/**
 * Base Idea class
 * Provides lazy loading and high-level abstraction over Object layer
 * Manages relationships between different entities
 * 
 * Child classes should:
 * - Hold reference to Object_ instance
 * - Implement lazy loading of related entities
 * - Cache loaded relationships
 * - Provide read-only access to data
 */
abstract class Idea
{
    /**
     * Cache for related data
     * 
     * @var array<string, mixed>
     */
    protected array $relatedCache = [];

    protected function __clone()
    {
    }

    protected function __construct()
    {
    }

    /**
     * Flush global cache for this Idea type
     * Should be implemented by child classes
     */
    abstract public static function flushCache(): void;

    /**
     * Debug info
     */
    public function __debugInfo(): array
    {
        return $this->toArray();
    }

    /**
     * Convert to array
     * 
     * @param bool $withId Include ID fields
     * @param bool $idAsIndex Use ID as array index (used in collections)
     * @param bool $withBridges Include bridge/junction table data
     * @param bool $withCalculation Include calculated/computed fields
     */
    public function toArray(bool $withId = true, bool $idAsIndex = true, bool $withBridges = false, bool $withCalculation = false): array
    {
        return [];
    }

    /**
     * Clear related data cache for this instance
     */
    protected function clearRelatedCache(): void
    {
        $this->relatedCache = [];
    }

    /**
     * Get cached related data
     */
    protected function getCachedRelated(string $key): mixed
    {
        return $this->relatedCache[$key] ?? null;
    }

    /**
     * Set cached related data
     */
    protected function setCachedRelated(string $key, mixed $value): void
    {
        $this->relatedCache[$key] = $value;
    }

    /**
     * Check if related data is cached
     */
    protected function hasRelatedCache(string $key): bool
    {
        return array_key_exists($key, $this->relatedCache);
    }
}
