<?php

namespace Hilos\Database\Object;

use ArrayAccess;
use Countable;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\TableConstants;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\EntityCollection;
use Hilos\Database\Filter\FilterInterface;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\SqlParam;
use Hilos\Database\SqlParamCollection;
use InvalidArgumentException;
use Iterator;

/**
 * Abstract base class for Object collections
 * Provides lazy loading support and common collection operations
 *
 * Child classes should define OBJECT_CLASS, ENTITY_COLLECTION_CLASS, and COLLECTION_KEY constants
 * to get default implementations of all collection methods.
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

    /** @var class-string<Object_> */
    public const string OBJECT_CLASS = '';

    /** @var class-string<EntityCollection> */
    public const string ENTITY_COLLECTION_CLASS = '';

    /** Collection key for TruthSourceRegistry */
    public const string COLLECTION_KEY = '';

    /**
     * Lazy loading strategies
     */
    public const int LAZY_STRATEGY_NONE = 0;           // Never lazy load, always full load
    public const int LAZY_STRATEGY_KEY = 1;            // Lazy load by key only, never load all
    public const int LAZY_STRATEGY_BATCH = 2;          // Lazy load by key, but load all on iteration
    public const int LAZY_STRATEGY_FULL_ON_ACCESS = 3; // Load all on first access

    /** @var T[] */
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
     * @return static Collection instance
     * @throws DatabaseException If configuration fails
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
     * Load all objects from database.
     *
     * Clears existing objects and loads all from database.
     *
     * @throws DatabaseException If database query fails
     */
    public function loadAllFromDB(): void
    {
        $this->objects = [];
        $entityCollectionClass = static::ENTITY_COLLECTION_CLASS;
        $objectClass = static::OBJECT_CLASS;
        $entityCollection = $entityCollectionClass::initFullDB();
        foreach ($entityCollection as $key => $entity) {
            $this->objects[$key] = $objectClass::fromEntity($entity);
        }
        $this->_allLoaded = true;
        $this->_allowLazyLoading = false;
    }

    /**
     * Initialize collection with all objects from database
     *
     * @return static Collection instance
     * @throws DatabaseException If configuration fails
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
    public static function initEmpty(): static
    {
        return new static();
    }

    /**
     * Lazy load object by key
     *
     * @param int|string $key Object key (usually primary key)
     * @return ?T
     */
    protected function lazyLoadObject(int|string $key): ?Object_
    {
        $objectClass = static::OBJECT_CLASS;
        $entityClass = $objectClass::ENTITY_CLASS;
        $entity = $entityClass::getById((int)$key);
        return $entity !== null ? $objectClass::fromEntity($entity) : null;
    }

    /**
     * Lazy load total count from database.
     *
     * @return int Row count for collection
     */
    protected function lazyLoadCount(): int
    {
        $resultSetCollection = Database::sql(
            "SELECT COUNT(*) as count FROM `" . $this->getTableName() . "`"
        );
        $firstResultSet = $resultSetCollection->first();

        if ($firstResultSet === null) {
            return 0;
        }

        $row = $firstResultSet->first();
        return $row !== null ? (int)($row['count'] ?? 0) : 0;
    }

    /**
     * Load all objects from database (for batch strategy)
     * Only loads objects that aren't already in memory.
     */
    protected function lazyLoadAll(): void
    {
        $entityCollectionClass = static::ENTITY_COLLECTION_CLASS;
        $objectClass = static::OBJECT_CLASS;
        $entityCollection = $entityCollectionClass::initFullDB();

        foreach ($entityCollection as $key => $entity) {
            if (!isset($this->objects[$key])) {
                $this->objects[$key] = $objectClass::fromEntity($entity);
            }
        }

        $this->_allLoaded = true;
    }

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

    /**
     * Returns entity column names eligible for full-text search.
     * Override in subclasses to restrict searchable columns.
     * Default: all columns from the entity.
     *
     * @return array<string> Entity column names
     */
    public function getSearchableColumns(): array
    {
        $objectClass = static::OBJECT_CLASS;
        $entityClass = $objectClass::ENTITY_CLASS;
        return $entityClass::_columns;
    }

    /**
     * Query a page of objects from DB with search, sort and pagination.
     * Loaded objects are merged into $this->objects (common storage).
     *
     * @param TableQueryDTO $query Query parameters
     *
     * @return array{objects: array<int|string, Object_>, totalCount: int}
     * @throws DatabaseException If database query fails
     */
    public function queryPage(TableQueryDTO $query): array
    {
        $objectClass = static::OBJECT_CLASS;
        $entityClass = $objectClass::ENTITY_CLASS;

        $filters = '';
        $filtersParam = [];

        if ($query->search !== '') {
            $columns = $this->getSearchableColumns();
            $likeParts = [];
            foreach ($columns as $column) {
                $likeParts[] = "`{$column}` LIKE ?";
                $filtersParam[] = SqlParam::string("%{$query->search}%");
            }
            if (!empty($likeParts)) {
                $filters = '(' . implode(' OR ', $likeParts) . ')';
            }
        }

        $orderBy = [];
        if ($query->orderBy !== '') {
            $orderBy[$query->orderBy] = $query->orderDirection;
        }

        $entityCollection = $entityClass::get(
            filters: $filters,
            filtersParam: $filtersParam,
            orderBy: $orderBy,
            limit: $query->limit,
            offset: $query->offset,
        );

        $totalCount = $entityClass::count(
            filters: $filters,
            filtersParam: $filtersParam,
        );

        $pageObjects = [];
        foreach ($entityCollection as $key => $entity) {
            if (isset($this->objects[$key])) {
                $pageObjects[$key] = $this->objects[$key];
            } else {
                $object = $objectClass::fromEntity($entity);
                $this->objects[$key] = $object;
                $pageObjects[$key] = $object;
            }
        }

        return [TableConstants::RESULT_KEY_OBJECTS => $pageObjects, TableConstants::RESULT_KEY_TOTAL_COUNT => $totalCount];
    }

    protected function __construct()
    {
    }

    protected function __clone()
    {
    }

    /**
     * Backup current iterator index for later restore.
     */
    public function backupIndex(): void
    {
        $this->backupIndex = $this->index;
    }

    /**
     * Restore iterator index from backup.
     */
    public function restoreIndex(): void
    {
        $this->index = $this->backupIndex;
    }

    /**
     * Debug info for var_dump/print_r.
     *
     * @return array<string, mixed> Collection data as array
     */
    public function __debugInfo(): array
    {
        return $this->toArray();
    }

    /**
     * Get current object
     * For batch strategy, loads all objects on first iteration
     *
     * @return ?T
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
            return null;
        }

        return $this->objects[$keys[$this->index]];
    }

    /**
     * Move iterator to next object.
     */
    public function next(): void
    {
        $this->index++;
    }

    /**
     * Get current iterator key.
     *
     * @return int|string Current array key
     */
    public function key(): string|int
    {
        return array_keys($this->objects)[$this->index];
    }

    /**
     * Check if current position is valid.
     * For KEY strategy, we need to check if we can load more.
     *
     * @return bool True if current position has valid object
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
            return false;
        }

        return false;
    }

    /**
     * Reset iterator to first position.
     */
    public function rewind(): void
    {
        $this->index = 0;
    }

    /**
     * Set object at offset.
     *
     * @param mixed $offset Array key (int or string)
     * @param T $value Object instance to set
     */
    public function offsetSet($offset, $value): void
    {
        if (!($value instanceof Object_)) {
            return;
        }
        $objectClass = static::OBJECT_CLASS;
        if ($objectClass !== '' && !($value instanceof $objectClass)) {
            return;
        }
        if ($offset === null) {
            $this->objects[] = $value;
        } else {
            $this->objects[$offset] = $value;
        }
    }

    /**
     * Check if offset exists.
     *
     * @param mixed $offset Array key to check
     * @return bool True if offset exists
     */
    public function offsetExists($offset): bool
    {
        return isset($this->objects[$offset]);
    }

    /**
     * Unset object at offset.
     *
     * @param mixed $offset Array key to unset
     */
    public function offsetUnset($offset): void
    {
        unset($this->objects[$offset]);
    }

    /**
     * Get object at offset.
     *
     * Supports lazy loading if enabled.
     *
     * @param mixed $offset Array key (int or string)
     * @return ?T Object instance or null if not found
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
            if ($this->_lazyStrategy === self::LAZY_STRATEGY_KEY) {
                return $this->lazyLoadCount();
            }
            if ($this->_lazyStrategy === self::LAZY_STRATEGY_BATCH) {
                return $this->lazyLoadCount();
            }
        }

        return count($this->objects);
    }

    /**
     * Magic getter for object properties.
     *
     * @param string $property Property name (allowLazyLoading)
     * @return bool Property value
     * @throws InvalidArgumentException If property does not exist
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
     *
     * @return int Lazy loading strategy constant
     */
    public function getLazyStrategy(): int
    {
        return $this->_lazyStrategy;
    }

    /**
     * Check if all objects are loaded
     *
     * @return bool True if all objects are loaded
     */
    public function isAllLoaded(): bool
    {
        return $this->_allLoaded;
    }

    /**
     * Get table name derived from Entity class
     *
     * @return string Table name
     */
    public function getTableName(): string
    {
        $objectClass = static::OBJECT_CLASS;
        $entityClass = $objectClass::ENTITY_CLASS;
        return $entityClass::_table;
    }

    /**
     * Get collection key for TruthSourceRegistry
     *
     * @return string Collection key
     */
    public function getCollectionKey(): string
    {
        return static::COLLECTION_KEY;
    }

    /**
     * Deletes all rows from the table and clears the collection.
     *
     * @throws DatabaseException If delete fails
     */
    public function deleteAll(): void
    {
        Database::sql("DELETE FROM `" . $this->getTableName() . "` WHERE true;");
        $this->objects = [];
    }

    /**
     * Get first object in collection
     *
     * @return ?T
     */
    public function first(): ?Object_
    {
        if (empty($this->objects)) {
            return null;
        }

        $keys = array_keys($this->objects);
        return $this->objects[$keys[0]] ?? null;
    }

    /**
     * Get last object in collection
     *
     * @return ?T
     */
    public function last(): ?Object_
    {
        if (empty($this->objects)) {
            return null;
        }

        $keys = array_keys($this->objects);
        $lastKey = end($keys);
        return $this->objects[$lastKey] ?? null;
    }

    /**
     * Get object by key.
     *
     * @param int|string $key Object key (id or string)
     * @return ?T Object or null if not found
     */
    public function get(int|string $key): ?Object_
    {
        return $this->offsetGet($key);
    }

    /**
     * Filter collection by filter criteria
     * Uses truth source to avoid loading from DB if keys are already in memory
     * Currently works for LAZY_STRATEGY_NONE (when all objects are already loaded)
     *
     * @param FilterInterface $filter Filter criteria
     * @return FilteredCollection Filtered collection
     * @throws DatabaseException If truth source check or filter fails
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
                throw new DatabaseException("Filtering for lazy-loaded collections not yet implemented. Use LAZY_STRATEGY_NONE or ensure all objects are loaded.");
            }
        }

        return new FilteredCollection($this, $filteredObjects);
    }

    /**
     * Convert collection to array
     *
     * @return array<int|string, array<string, mixed>> Array of object arrays (key preserved)
     */
    public function toArray(): array
    {
        return array_map(function ($object) {
            return $object->toArray();
        }, $this->objects);
    }
}
