<?php

namespace Hilos\Database\Object;

use ArrayAccess;
use Countable;
use Generator;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\Table\TableSortWhitelist;
use Hilos\Core\Sync\DTO\DbSyncClearedSignalData;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Hilos;
use Hilos\Database\Entity\Collection\EntityCollection;
use Hilos\Database\Filter\FilterInterface;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\SqlParam;
use Hilos\Database\SqlSortDirection;
use Hilos\HilosException;
use IteratorAggregate;

/**
 * Abstract base class for Object collections.
 *
 * Provides lazy loading support and common collection operations.
 *
 * Child classes should define OBJECT_CLASS, ENTITY_COLLECTION_CLASS, and COLLECTION_KEY constants
 * to get default implementations of all collection methods.
 *
 * @template T of Object_
 * @implements ArrayAccess<int|string, T>
 * @implements IteratorAggregate<int|string, T>
 *
 * @property-read bool allowLazyLoading
 */
abstract class Objects implements IteratorAggregate, ArrayAccess, Countable
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

    /** @var array<string|int, T> objects stored in collection (key => Object_) */
    protected array $objects = [];

    /** @var bool whether lazy loading is enabled */
    protected bool $_allowLazyLoading = false;

    /** @var int lazy loading strategy */
    protected int $_lazyStrategy = self::LAZY_STRATEGY_NONE;

    /** @var bool whether all objects have been loaded */
    protected bool $_allLoaded = false;

    /**
     * Initialize collection with database loading strategy
     * Automatically determines loading behavior based on strategy
     *
     * @param int $strategy Lazy loading strategy (LAZY_STRATEGY_BATCH by default)
     * @return static Collection instance
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
     * @throws LogicException When entity collection class is not configured
     * @throws DatabaseException If database query fails
     * @throws HilosException When the concrete collection refuses to be loaded directly
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
     * Initialize empty collection.
     *
     * @return static Empty collection instance
     * @throws HilosException When the concrete collection refuses to be initialized directly
     */
    public static function initEmpty(): static
    {
        return new static();
    }

    /**
     * Lazy load object by key.
     *
     * @param int|string|null $key Object key (usually primary key), or null for a missing optional relation key
     * @return ?T Object instance or null if not found
     * @throws DatabaseException When SQL execution fails
     */
    protected function lazyLoadObject(int|string|null $key): ?Object_
    {
        if ($key === null) {
            return null;
        }
        $objectClass = static::OBJECT_CLASS;
        $entityClass = $objectClass::ENTITY_CLASS;
        $entity = $entityClass::getById((int)$key);
        return $entity !== null ? $objectClass::fromEntity($entity) : null;
    }

    /**
     * Lazy load total count from database.
     *
     * @return int Row count for collection
     * @throws DatabaseException When SQL execution fails
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
     *
     * @throws LogicException When entity collection class is not configured
     * @throws DatabaseException When loading the full object collection from the database fails
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
     *
     * @throws LogicException When entity collection class is not configured
     * @throws DatabaseException When loading the full object collection from the database fails
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
     * @return list<string> Entity column names
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
     * The sort field is held against the entity's own columns here as well as at the table
     * boundary: this is where a name becomes an SQL identifier, so it answers for itself
     * rather than trusting whoever built the query. A field that is no column of this entity
     * leaves the page in the table's default order.
     *
     * @param TableQueryDTO $query Query parameters
     * @return array<string, mixed> Keys: objects (array<int|string, Object_>), totalCount (int)
     * @throws DatabaseException If database query fails
     */
    public function queryPage(TableQueryDTO $query): array
    {
        $objectClass = static::OBJECT_CLASS;
        $entityClass = $objectClass::ENTITY_CLASS;

        $filters = '';
        $filtersParam = [];

        $search = $query->search;
        if ($search !== null && $search !== '') {
            $columns = $this->getSearchableColumns();
            $likeParts = [];
            foreach ($columns as $column) {
                $likeParts[] = "`{$column}` LIKE ?";
                $filtersParam[] = SqlParam::string("%{$search}%");
            }
            if (!empty($likeParts)) {
                $filters = '(' . implode(' OR ', $likeParts) . ')';
            }
        }

        $orderBy = [];
        $sort = TableSortWhitelist::resolve(
            $query->sort,
            array_combine($entityClass::_columns, $entityClass::_columns),
            $entityClass,
        );
        if ($sort !== null) {
            $orderBy[$sort->column ?? $sort->field] = $sort->direction === TableConstants::ORDER_DESC
                ? SqlSortDirection::DESC
                : SqlSortDirection::ASC;
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

    /**
     * Prevents direct instantiation. Use initEmpty() or initDB() instead.
     */
    protected function __construct()
    {
    }

    /**
     * Prevents cloning of collection instances.
     */
    protected function __clone(): void
    {
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
     * List the keys currently stored, in insertion order.
     *
     * For the batch strategy this is the point where the whole collection arrives, because a walk
     * over a partially loaded batch collection would silently be a walk over a prefix. The KEY
     * strategy answers with the already loaded keys only: loading all of them is exactly what that
     * strategy exists to avoid.
     *
     * @return list<int|string> Object keys
     * @throws LogicException When entity collection class is not configured
     * @throws DatabaseException When loading the full object collection from the database fails
     */
    public function keys(): array
    {
        if ($this->_allowLazyLoading &&
            $this->_lazyStrategy === self::LAZY_STRATEGY_BATCH &&
            !$this->_allLoaded) {
            $this->preloadAll();
        }

        return array_keys($this->objects);
    }

    /**
     * Walk the objects over a snapshot of the keys taken when the walk starts.
     *
     * Each walk gets its own generator, so a nested foreach over the same collection does not
     * disturb the outer one. A key removed after the snapshot was taken is skipped rather than
     * answered as null, and an object added during the walk is not seen.
     *
     * @return Generator<int|string, T> Object key => Object_
     * @throws LogicException When entity collection class is not configured
     * @throws DatabaseException When loading the full object collection from the database fails
     */
    public function getIterator(): Generator
    {
        foreach ($this->keys() as $key) {
            $object = $this->objects[$key] ?? null;
            if ($object === null) {
                continue;
            }
            yield $key => $object;
        }
    }

    /**
     * Set object at offset and announce the new membership of the mirror.
     *
     * Announced from here rather than from the roads that lead here, so that every road is
     * covered: what a dependent view has to hear is that this key now holds a different object.
     * Reading the collection out of the database goes through {@see self::hydrate()} instead,
     * which is what keeps a load from announcing rows as new.
     *
     * @param mixed $offset Array key (int or string), or null to append under the next one
     * @param T $value Object instance to set
     * @throws HilosException Whatever a subscriber to the announcement raises
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
            $offset = array_key_last($this->objects);
        } else {
            $this->objects[$offset] = $value;
        }
        $collectionKey = $this->getCollectionKey();
        if ($collectionKey === '') {
            return;
        }
        SourceChangeBus::publish(SourceChange::dbCreated(
            $collectionKey,
            (string)$offset,
            $value->toArray(),
            ExecutionContext::currentAcceptKey(),
        ));
    }

    /**
     * Put a row read out of storage into the store, announcing nothing.
     *
     * The silence is deliberate, and it is the whole reason this seam exists next to the door:
     * a load changes what this process HOLDS, not what the table CONTAINS. Sent through
     * {@see self::offsetSet()}, every row read back would be announced as a new membership of
     * the mirror, and dependent views would be told that rows they already show just appeared.
     *
     * @param int|string $key Array key the row is stored under
     * @param T $object Object read out of storage
     */
    protected function hydrate(int|string $key, Object_ $object): void
    {
        $this->objects[$key] = $object;
    }

    /**
     * Check if offset exists.
     *
     * @param mixed $offset Array key to check, or null for a missing optional relation key
     * @return bool True if offset exists
     */
    public function offsetExists($offset): bool
    {
        if ($offset === null) {
            return false;
        }
        return isset($this->objects[$offset]);
    }

    /**
     * Unset object at offset and announce the lost membership of the mirror.
     *
     * The object is read out before the key is dropped, so the announcement carries what the key
     * held. A key holding nothing is not announced: nothing left the mirror, and the store is
     * read directly rather than through {@see self::offsetGet()} so that dropping an absent key
     * cannot fetch it from the database first.
     *
     * @param mixed $offset Array key to unset, or null for no-op
     * @throws HilosException Whatever a subscriber to the announcement raises
     */
    public function offsetUnset($offset): void
    {
        if ($offset === null) {
            return;
        }
        $previous = $this->objects[$offset] ?? null;
        unset($this->objects[$offset]);
        $collectionKey = $this->getCollectionKey();
        if ($previous === null || $collectionKey === '') {
            return;
        }
        SourceChangeBus::publish(SourceChange::dbDeleted(
            $collectionKey,
            (string)$offset,
            $previous->toArray(),
            ExecutionContext::currentAcceptKey(),
        ));
    }

    /**
     * Get object at offset.
     *
     * Supports lazy loading if enabled.
     *
     * @param mixed $offset Array key, or null for a missing optional relation key
     * @return ?T Object instance or null if not found
     * @throws LogicException When entity collection class is not configured
     * @throws DatabaseException When lazy-loading an object from the database fails
     */
    public function offsetGet($offset): ?Object_
    {
        if ($offset === null) {
            return null;
        }
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
     * Get count of objects.
     *
     * Supports lazy loading count from database.
     *
     * @return int Number of objects in collection
     * @throws DatabaseException When SQL execution fails
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
     * @throws InvalidArgumentException When the cleared DB-sync signal cannot be named
     */
    public function deleteAll(): void
    {
        Database::sql("DELETE FROM `" . $this->getTableName() . "` WHERE true;");
        $this->clearInMemory();

        $collectionKey = static::COLLECTION_KEY;
        if ($collectionKey !== '') {
            Hilos::$sr?->queueDbSyncClearedSignal(
                new DbSyncClearedSignalData($collectionKey, ExecutionContext::currentAcceptKey()),
            );
        }
    }

    /**
     * Drops every row from the in-memory collection without touching the database.
     *
     * Local half of deleteAll(): the DELETE ran in this process, so the rows are known
     * to be gone and dropping them needs no re-read. An incoming DB_SYNC_CLEARED from
     * another process goes through reHydrate() instead — there the mirror has to be
     * reconciled with the table rather than assumed empty.
     */
    public function clearInMemory(): void
    {
        $this->objects = [];
    }

    /**
     * Resets the collection to its fresh post-initDB() state when this process can no
     * longer trust its mirror: the DB was replaced under a live daemon (external
     * db-reset or restore), or another process reported a truncate.
     *
     * Unlike clearInMemory(), which drops rows this process just deleted itself, this
     * re-reads the DB so the daemon stops holding rows it only assumes.
     *
     * Two collections reload at once: an eager one (LAZY_STRATEGY_NONE), and a lazy one that
     * somebody had declared full by {@see preloadAll()}. The second is the one worth naming
     * (HIL-670): fullness there is a declaration, not a strategy, and something is drawing a
     * list from it on the strength of that declaration. A reset that only remembered the
     * strategy would leave such a collection quietly no longer whole, and a list drawn from it
     * would be missing rows with nothing to say so. Every other lazy collection drops its rows
     * and fetches them from the fresh DB on next access, exactly as before.
     *
     * @throws LogicException When the entity collection class is not configured (eager reload)
     * @throws DatabaseException If reloading the full collection from the fresh DB fails (eager reload)
     * @throws HilosException When the concrete collection refuses to be loaded directly
     */
    public function reHydrate(): void
    {
        // Read before the line below drops it: what has to survive the reset is the CLAIM this
        // collection was making about itself, and that claim is gone one statement later.
        $wasAllLoaded = $this->_allLoaded;

        $this->objects = [];
        // Dropped before the reload, not after: a failed read then leaves the collection
        // empty AND not loaded, so the next access reads the DB again. Left true, a failed
        // read would pin an empty mirror over a non-empty table for the process lifetime.
        $this->_allLoaded = false;

        if ($this->_lazyStrategy === self::LAZY_STRATEGY_NONE || $wasAllLoaded) {
            $this->loadAllFromDB();
        }
    }

    /**
     * Get first object in collection.
     *
     * @return ?T First Object_ or null if collection empty
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
     * Get last object in collection.
     *
     * @return ?T Last Object_ or null if collection empty
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
     * @param int|string|null $key Object key (id or string), or null for a missing optional relation key
     * @return ?T Object or null if not found
     * @throws LogicException When entity collection class is not configured
     * @throws DatabaseException When lazy-loading an object from the database fails
     */
    public function get(int|string|null $key): ?Object_
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
     * @throws LogicException When filtering a lazy-loaded collection that is not fully loaded (unsupported)
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
                throw new LogicException('Filtering for lazy-loaded collections not yet implemented.'
                    . ' Use LAZY_STRATEGY_NONE or ensure all objects are loaded.');
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
