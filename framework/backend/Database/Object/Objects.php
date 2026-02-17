<?php

namespace Hilos\Database\Object;

use ArrayAccess;
use Countable;
use Hilos\Database\DatabaseException;
use Hilos\Database\Filter\FilterInterface;
use Hilos\Database\Idea\TruthSourceRegistry;
use Hilos\Database\Object\Item\Object_;
use InvalidArgumentException;
use Iterator;

/**
 * Abstract base class for Object collections
 * Provides lazy loading support and common collection operations
 *
 * @template T of \Hilos\Database\Object\Item\Object_
 * @implements ArrayAccess<int|string, T>
 * @implements Iterator<int|string, T>
 *
 * @property-read bool allowLazyLoading
 */
abstract class Objects implements Iterator, ArrayAccess, Countable
{
    public const string allowLazyLoading = 'allowLazyLoading';

    /**
     * Lazy loading strategies
     */
    public const int LAZY_STRATEGY_NONE = 0;           // Never lazy load, always full load
    public const int LAZY_STRATEGY_KEY = 1;            // Lazy load by key only, never load all
    public const int LAZY_STRATEGY_BATCH = 2;          // Lazy load by key, but load all on iteration
    public const int LAZY_STRATEGY_FULL_ON_ACCESS = 3; // Load all on first access

    /** @var \Hilos\Database\Object\Item\Object_[] */
    protected array $objects = [];

    /** @var bool */
    protected bool $_allowLazyLoading = false;

    /** @var int Lazy loading strategy */
    protected int $_lazyStrategy = self::LAZY_STRATEGY_NONE;

    /** @var bool Whether all objects have been loaded */
    protected bool $_allLoaded = false;

    /** @var int */
    protected int $index = 0;

    /** @var int */
    private int $backupIndex = 0;

    /**
     * Initialize collection with database loading strategy
     * Automatically determines loading behavior based on strategy
     *
     * @param int $strategy Lazy loading strategy (LAZY_STRATEGY_BATCH by default)
     * @return static
     * @throws DatabaseException
     */
    public static function initDB(int $strategy = self::LAZY_STRATEGY_BATCH): static
    {
        $self = new static();

        // For LAZY_STRATEGY_NONE, configure for lazy loading on first access
        // Data will be loaded when collection is first accessed (read or write)
        if ($strategy === self::LAZY_STRATEGY_NONE) {
            $self->_allowLazyLoading = false;
            $self->_lazyStrategy = self::LAZY_STRATEGY_NONE;
            $self->_allLoaded = false; // Will be loaded on first access
            // Do NOT load all data here - load on first access instead
        } else {
            $self->_allowLazyLoading = true;
            $self->_lazyStrategy = $strategy;
            $self->_allLoaded = false;
        }

        return $self;
    }

    /**
     * Load all objects from database
     * Clears existing objects and loads all from database
     * Must be implemented by child classes
     *
     * @throws DatabaseException
     */
    abstract public function loadAllFromDB(): void;

    /**
     * Initialize collection with all objects from database
     *
     * @return static
     * @throws DatabaseException
     * @deprecated Use initDB(LAZY_STRATEGY_NONE) instead
     */
    public static function initFullDB(): static
    {
        return static::initDB(self::LAZY_STRATEGY_NONE);
    }

    /**
     * Initialize empty collection
     *
     * @return static
     */
    abstract public static function initEmpty(): static;

    /**
     * Lazy load object by key (only if allowLazyLoading is true)
     * Must be implemented by child classes
     *
     * @param int|string $key Object key (usually primary key)
     * @return ?Object_
     */
    abstract protected function lazyLoadObject(int|string $key): ?Object_;

    /**
     * Lazy load count from database (only if allowLazyLoading is true)
     * Must be implemented by child classes
     *
     * @return int
     */
    abstract protected function lazyLoadCount(): int;

    /**
     * Load all objects from database (for batch strategy)
     * Must be implemented by child classes
     */
    abstract protected function lazyLoadAll(): void;

    /**
     * Preload all objects (explicit full load)
     */
    public function preloadAll(): void
    {
        if (!$this->_allLoaded && $this->_allowLazyLoading) {
            $this->lazyLoadAll();
            $this->_allLoaded = true;
        }
    }

    protected function __construct()
    {
    }

    protected function __clone()
    {
    }

    /**
     * Backup current iterator index
     */
    public function backupIndex(): void
    {
        $this->backupIndex = $this->index;
    }

    /**
     * Restore iterator index from backup
     */
    public function restoreIndex(): void
    {
        $this->index = $this->backupIndex;
    }

    /**
     * Debug info
     */
    public function __debugInfo(): array
    {
        return $this->toArray();
    }

    /**
     * Get current object
     * For batch strategy, loads all objects on first iteration
     *
     * @return Object_
     */
    public function current(): ?Object_
    {
        // For batch strategy, load all on first iteration
        if ($this->_allowLazyLoading &&
            $this->_lazyStrategy === self::LAZY_STRATEGY_BATCH &&
            !$this->_allLoaded) {
            $this->lazyLoadAll();
            $this->_allLoaded = true;
        }

        $keys = array_keys($this->objects);
        if (!isset($keys[$this->index])) {
            // Return null if position is invalid
            return null;
        }

        return $this->objects[$keys[$this->index]];
    }

    /**
     * Move to next object
     */
    public function next(): void
    {
        $this->index++;
    }

    /**
     * Get current key
     *
     * @return string|int
     */
    public function key(): string|int
    {
        return array_keys($this->objects)[$this->index];
    }

    /**
     * Check if current position is valid
     * For KEY strategy, we need to check if we can load more
     */
    public function valid(): bool
    {
        $keys = array_keys($this->objects);

        if (isset($keys[$this->index])) {
            return true;
        }

        // For KEY strategy, we can't iterate over all (would require loading all)
        // So iteration is only valid for already loaded objects
        if ($this->_allowLazyLoading && $this->_lazyStrategy === self::LAZY_STRATEGY_KEY) {
            return false; // Can't iterate over all in KEY strategy
        }

        return false;
    }

    /**
     * Reset iterator
     */
    public function rewind(): void
    {
        $this->index = 0;
    }

    /**
     * Set object at offset
     *
     * @param mixed $offset
     * @param mixed $value
     */
    public function offsetSet($offset, $value): void
    {
        if ($value instanceof Object_) {
            if ($offset === null) {
                $this->objects[] = $value;
            } else {
                $this->objects[$offset] = $value;
            }
        }
    }

    /**
     * Check if offset exists
     *
     * @param mixed $offset
     */
    public function offsetExists($offset): bool
    {
        return isset($this->objects[$offset]);
    }

    /**
     * Unset object at offset
     *
     * @param mixed $offset
     */
    public function offsetUnset($offset): void
    {
        unset($this->objects[$offset]);
    }

    /**
     * Get object at offset
     * Supports lazy loading if enabled
     *
     * @param mixed $offset
     * @return ?Object_
     */
    public function offsetGet($offset): ?Object_
    {
        if (isset($this->objects[$offset])) {
            return $this->objects[$offset];
        }

        // Lazy load if enabled and strategy allows
        if ($this->_allowLazyLoading && !$this->_allLoaded) {
            if ($this->_lazyStrategy === self::LAZY_STRATEGY_KEY ||
                $this->_lazyStrategy === self::LAZY_STRATEGY_BATCH) {
                $object = $this->lazyLoadObject($offset);
                if ($object !== null) {
                    $this->objects[$offset] = $object;
                }
                return $object;
            } elseif ($this->_lazyStrategy === self::LAZY_STRATEGY_FULL_ON_ACCESS) {
                $this->lazyLoadAll();
                $this->_allLoaded = true;
                return $this->objects[$offset] ?? null;
            }
        }

        return null;
    }

    /**
     * Get count of objects
     * Supports lazy loading count from database
     */
    public function count(): int
    {
        if ($this->_allowLazyLoading && !$this->_allLoaded) {
            // For KEY strategy, we need to query DB for count
            if ($this->_lazyStrategy === self::LAZY_STRATEGY_KEY) {
                return $this->lazyLoadCount();
            }
            // For other strategies, if we haven't loaded all, get count from DB
            // But if we have some loaded, we might want to load all first
            if ($this->_lazyStrategy === self::LAZY_STRATEGY_BATCH) {
                // Could return DB count or load all - let's return DB count for efficiency
                return $this->lazyLoadCount();
            }
        }

        return count($this->objects);
    }

    /**
     * Magic getter
     *
     * @param string $property
     * @return bool
     * @throws InvalidArgumentException
     */
    public function __get(string $property): bool
    {
        return match ($property) {
            self::allowLazyLoading => $this->_allowLazyLoading,
            default => throw new InvalidArgumentException("Property [{$property}] does not exist on " . static::class),
        };
    }

    /**
     * Get lazy loading strategy
     * Used by Actions to check if LAZY_STRATEGY_NONE is enabled
     *
     * @return int Lazy loading strategy constant
     */
    public function getLazyStrategy(): int
    {
        return $this->_lazyStrategy;
    }

    /**
     * Check if all objects are loaded
     * Used by Actions to check if data needs to be loaded
     *
     * @return bool True if all objects are loaded
     */
    public function isAllLoaded(): bool
    {
        return $this->_allLoaded;
    }

    /**
     * Get table name from Entity class
     * Must be implemented in child classes
     *
     * @return string Table name
     */
    abstract public function getTableName(): string;

    /**
     * Get collection key (Idea key, e.g. 'events', 'users')
     * Used for TruthSourceRegistry. Must be implemented in child classes.
     *
     * @return string Collection key
     */
    abstract public function getCollectionKey(): string;

    /**
     * Filter collection by filter criteria
     * Uses truth source to avoid loading from DB if keys are already in memory
     * Currently works for LAZY_STRATEGY_NONE (when all objects are already loaded)
     *
     * @param FilterInterface $filter Filter criteria
     * @return FilteredCollection Filtered collection
     * @throws DatabaseException
     */
    public function filter(FilterInterface $filter): FilteredCollection
    {
        $collectionKey = $this->getCollectionKey();
        $truthSourceKeys = TruthSourceRegistry::getTruthSourceKeys($collectionKey);

        $filteredObjects = [];

        // If there is a truth source - filter from memory
        if ($truthSourceKeys !== null && $truthSourceKeys !== true) {
            // Filter only objects from truth source
            foreach ($truthSourceKeys as $key) {
                if (isset($this->objects[$key])) {
                    $object = $this->objects[$key];
                    if ($filter->matches($object)) {
                        $filteredObjects[$key] = $object;
                    }
                }
            }
        } elseif ($truthSourceKeys === true) {
            // All keys are truth source, filter all loaded objects
            foreach ($this->objects as $key => $object) {
                if ($filter->matches($object)) {
                    $filteredObjects[$key] = $object;
                }
            }
        } else {
            // No truth source
            // For LAZY_STRATEGY_NONE or when all loaded - filter from memory
            if (!$this->_allowLazyLoading || $this->_allLoaded) {
                foreach ($this->objects as $key => $object) {
                    if ($filter->matches($object)) {
                        $filteredObjects[$key] = $object;
                    }
                }
            } else {
                // For lazy strategies - SQL query with filter
                // TODO: Implement for other lazy loading strategies
                // Currently only for fully loaded collections
                throw new DatabaseException("Filtering for lazy-loaded collections not yet implemented. Use LAZY_STRATEGY_NONE or ensure all objects are loaded.");
            }
        }

        return new FilteredCollection($this, $filter, $filteredObjects);
    }

    /**
     * Convert collection to array
     *
     * @return array
     */
    public function toArray(): array
    {
        return array_map(function ($object) {
            return $object->toArray();
        }, $this->objects);
    }
}
