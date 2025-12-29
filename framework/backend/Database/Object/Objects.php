<?php

namespace Hilos\Database\Object;

use ArrayAccess;
use Countable;
use Hilos\Database\Filter\FilterInterface;
use Hilos\Database\Idea\TruthSourceRegistry;
use Hilos\Database\Database;
use Hilos\Database\Entity\Entity;
use Hilos\Database\SqlParamCollection;
use Hilos\Exception\DatabaseException;
use Iterator;
use ReflectionClass;

/**
 * Abstract base class for Object collections
 * Provides lazy loading support and common collection operations
 *
 * @template T of Object_
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

    /** @var Object_[] */
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
     * Initialize collection with all objects from database
     *
     * @return self
     */
    abstract public static function initFullDB(): self;

    /**
     * Initialize collection with partial database loading (lazy loading enabled)
     *
     * @param int $strategy Lazy loading strategy (LAZY_STRATEGY_KEY, LAZY_STRATEGY_BATCH, etc.)
     * @return self
     */
    abstract public static function initPartialDB(int $strategy = self::LAZY_STRATEGY_BATCH): self;

    /**
     * Initialize empty collection
     *
     * @return self
     */
    abstract public static function initEmpty(): self;

    /**
     * Reload all objects from database
     */
    abstract public function initAgainFullDB(): void;

    /**
     * Reload with partial database loading (lazy loading enabled)
     *
     * @param int $strategy Lazy loading strategy
     */
    public function initAgainPartialDB(int $strategy = self::LAZY_STRATEGY_BATCH): void
    {
        $this->initAgainEmpty();
        $this->_allowLazyLoading = true;
        $this->_lazyStrategy = $strategy;
        $this->_allLoaded = false;
    }

    /**
     * Clear collection
     */
    public function initAgainEmpty(): void
    {
        $this->objects = [];
        $this->_allLoaded = false;
    }

    /**
     * Lazy load object by key (only if allowLazyLoading is true)
     * Must be implemented by child classes
     *
     * @param int|string $key Object key (usually primary key)
     * @return Object_|null
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
     * @return Object_|null
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
     * @throws \InvalidArgumentException
     */
    public function __get(string $property): bool
    {
        return match ($property) {
            self::allowLazyLoading => $this->_allowLazyLoading,
            default => throw new \InvalidArgumentException("Property [{$property}] does not exist on " . static::class),
        };
    }

    /**
     * Get table name from Entity class
     * Uses reflection to get _table constant from Entity
     * 
     * @return string Table name
     */
    protected function getTableName(): string
    {
        // Get first object to determine Entity class
        $firstObject = reset($this->objects);
        if ($firstObject === false) {
            // No objects loaded, try to get from lazyLoadObject
            // For now, throw exception - child classes should override this
            throw new DatabaseException("Cannot determine table name: no objects loaded and getTableName() not overridden");
        }

        // Get Entity class through reflection
        $reflection = new ReflectionClass($firstObject);
        $entityProperty = $reflection->getProperty('entity');
        $entityProperty->setAccessible(true);
        $entity = $entityProperty->getValue($firstObject);

        if (!($entity instanceof Entity)) {
            throw new DatabaseException("Object does not contain Entity instance");
        }

        // Get _table constant from Entity class
        $entityReflection = new ReflectionClass($entity);
        $tableConstant = $entityReflection->getConstant('_table');
        
        if ($tableConstant === false) {
            throw new DatabaseException("Entity class does not have _table constant");
        }

        return $tableConstant;
    }

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
        $table = $this->getTableName();
        $truthSourceKeys = TruthSourceRegistry::getTruthSourceKeys($table);
        
        $filteredObjects = [];
        
        // Если есть источник истины - фильтровать из памяти
        if ($truthSourceKeys !== null && $truthSourceKeys !== true) {
            // Фильтровать только объекты из источника истины
            foreach ($truthSourceKeys as $key) {
                if (isset($this->objects[$key])) {
                    $object = $this->objects[$key];
                    if ($filter->matches($object)) {
                        $filteredObjects[$key] = $object;
                    }
                }
            }
        } elseif ($truthSourceKeys === true) {
            // Все ключи - источник истины, фильтровать все загруженные
            foreach ($this->objects as $key => $object) {
                if ($filter->matches($object)) {
                    $filteredObjects[$key] = $object;
                }
            }
        } else {
            // Нет источника истины
            // Для LAZY_STRATEGY_NONE или когда все загружено - фильтровать из памяти
            if (!$this->_allowLazyLoading || $this->_allLoaded) {
                foreach ($this->objects as $key => $object) {
                    if ($filter->matches($object)) {
                        $filteredObjects[$key] = $object;
                    }
                }
            } else {
                // Для lazy стратегий - SQL запрос с фильтром
                // TODO: Реализовать для других стратегий lazy loading
                // Пока только для полностью загруженных коллекций
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
