<?php

declare(strict_types=1);

namespace Hilos\Database\Context;

use Hilos\Core\Table\Actions\TableItemActions;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\Actions\Collection\DbActions;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\View\CloneNotAllowedException;
use Hilos\Database\Exception\View\CollectionNotFoundException;
use Hilos\Database\Exception\View\ObjectCollectionNotFoundException;
use Hilos\Database\Exception\View\UnknownLazyStrategyException;
use Hilos\Database\Object\Objects;
use Hilos\Database\View\Collection\DbCollection;

/**
 * DbContext - Database context (instance layer only).
 * Contains object collections and their DB collection wrappers.
 *
 * @template T of DbContext
 */
abstract class DbContext
{
    /**
     * Object collections (references to Objects instances)
     * These are set by setRepresent() method.
     *
     * @var array<string, Objects>
     */
    protected array $_objectCollections = [];

    /**
     * DB item collections (wrappers around Object collections)
     * These are created automatically from Object collections.
     *
     * @var array<string, DbCollection>
     */
    protected array $_dbItemCollections = [];

    /**
     * Baseline DB generation marker, sampled at init() and after each re-hydration.
     * Null until refreshDbGeneration() first reads it (or when the marker is
     * unavailable, e.g. no DB connection). A change signals the DB was replaced
     * under the live process (external db-reset or restore).
     *
     * @var ?string
     */
    protected ?string $_dbGeneration = null;

    /**
     * Creates DB context.
     *
     * Called from facade createDb().
     */
    public function __construct()
    {
    }

    /**
     * Public clone - prevent cloning.
     *
     * @throws CloneNotAllowedException Always, cloning not allowed
     */
    public function __clone(): void
    {
        throw new CloneNotAllowedException('DbContext cannot be cloned');
    }

    /**
     * Set representation for object collection.
     *
     * @param string $name Collection name (e.g. users)
     * @param class-string<DbCollection> $dbItemCollectionClass DB collection class name
     * @param ?class-string<DbActions> $actionsClass Collection actions class name (optional)
     * @param ?class-string<TableItemActions> $itemActionsClass Item actions class name (optional)
     * @throws ObjectCollectionNotFoundException When object collection not found
     */
    public function setRepresent(string $name, string $dbItemCollectionClass, ?string $actionsClass = null, ?string $itemActionsClass = null): void
    {
        $objectCollection = $this->_objectCollections[$name]
            ?? throw new ObjectCollectionNotFoundException(
                "Object collection [{$name}] not found in _objectCollections. Create it before calling setRepresent()."
            );

        if (is_subclass_of($dbItemCollectionClass, DbCollection::class)) {
            $dbItemCollection = $dbItemCollectionClass::init();
            $dbItemCollection->setObjectCollection($objectCollection);
            if ($actionsClass !== null) {
                $dbItemCollection->setActionsClass($actionsClass);
            }
            if ($itemActionsClass !== null) {
                $dbItemCollection->setItemActionsClass($itemActionsClass);
            }
            $this->_dbItemCollections[$name] = $dbItemCollection;
        }
    }

    /**
     * Get object collection by name.
     *
     * @param string $name Collection name (e.g. users, events)
     * @return ?Objects Object collection or null if not found
     */
    public function getObjectCollection(string $name): ?Objects
    {
        return $this->_objectCollections[$name] ?? null;
    }

    /**
     * Re-reads one collection from the database and drops its cached DbItems.
     *
     * Used by DB_SYNC_CLEARED apply to follow a remote deleteAll() truncate. The
     * physical DELETE already ran in the originating process, so after a legitimate
     * clear this re-read returns nothing and costs one empty SELECT. It is a re-read
     * rather than a blind blanking so that applying the same clear twice converges on
     * whatever the table now holds instead of leaving the mirror stuck empty over rows
     * written after the truncate.
     *
     * @param string $name Collection name (e.g. users, events)
     * @throws LogicException When the collection entity class is not configured (eager reload)
     * @throws DatabaseException If reloading an eager collection from the database fails
     */
    public function reHydrateCollection(string $name): void
    {
        ($this->_objectCollections[$name] ?? null)?->reHydrate();
        ($this->_dbItemCollections[$name] ?? null)?->clearCache();
    }

    /**
     * Re-hydrates every DB-backed collection from the current DB.
     *
     * Used when the DB was replaced under the live process (external db-reset or
     * restore): each object collection is reset to its fresh post-initDB state
     * (eager collections reload now, lazy ones on next access) and each DbItem
     * wrapper cache is dropped so it does not return stale items. Does not touch
     * the DB generation baseline; callers that detected a change refresh it.
     *
     * @throws LogicException When a represented collection entity class is not configured (eager reload)
     * @throws DatabaseException If reloading an eager collection from the fresh DB fails
     */
    public function reHydrateDbBackedCollections(): void
    {
        foreach ($this->_objectCollections as $name => $objectCollection) {
            $objectCollection->reHydrate();
            ($this->_dbItemCollections[$name] ?? null)?->clearCache();
        }
    }

    /**
     * Samples the current DB generation as the baseline. Called from Hilos::init()
     * after configure() so a later reHydrateIfDbChanged() can detect a replacement.
     */
    public function refreshDbGeneration(): void
    {
        $this->_dbGeneration = $this->readDbGeneration();
    }

    /**
     * Re-hydrates all DB-backed collections when the DB generation changed since the
     * baseline, the fallback for a raw db-reset that fires no restore signal.
     *
     * On a detected change it re-hydrates and refreshes the baseline, so a stale
     * in-memory row that collided with a freshly-minted id is replaced rather than
     * rejected. Returns false when the generation is unchanged or unreadable, so a
     * genuine duplicate id still surfaces as an error.
     *
     * @return bool True when the DB changed and collections were re-hydrated
     * @throws LogicException When a represented collection entity class is not configured (eager reload)
     * @throws DatabaseException If reloading an eager collection from the fresh DB fails
     */
    public function reHydrateIfDbChanged(): bool
    {
        $current = $this->readDbGeneration();
        if ($current === null || $current === $this->_dbGeneration) {
            return false;
        }

        $this->reHydrateDbBackedCollections();
        $this->_dbGeneration = $current;

        return true;
    }

    /**
     * Reads a marker that changes when the DB schema is dropped and recreated.
     *
     * Default reads the max table CREATE_TIME plus table count from
     * information_schema, which jumps on the DROP+CREATE of a db-reset or restore.
     * Returns null when the marker cannot be read (e.g. no DB connection), which
     * callers treat as "unchanged". Overridable so unit tests can drive the marker
     * without a real DB.
     *
     * @return ?string DB generation marker, or null when unavailable
     */
    protected function readDbGeneration(): ?string
    {
        try {
            $row = Database::sql(
                "SELECT MAX(`CREATE_TIME`) AS `create_time`, COUNT(*) AS `table_count` "
                . "FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = DATABASE()"
            )->first()?->first();
        } catch (DatabaseException) {
            return null;
        }

        if ($row === null) {
            return null;
        }

        return (string)($row['create_time'] ?? '') . ':' . (string)($row['table_count'] ?? '');
    }

    /**
     * Get DB collection by name (magic getter for $db->users, $db->events, etc.).
     *
     * @param string $name Collection name (e.g. users, events)
     * @return DbCollection DB collection instance
     * @throws CollectionNotFoundException When collection does not exist
     * @throws UnknownLazyStrategyException When lazy strategy is unknown
     * @throws LogicException When the object collection entity class is not configured
     * @throws DatabaseException On connection or schema error
     */
    public function __get(string $name)
    {
        if (!isset($this->_dbItemCollections[$name])) {
            throw new CollectionNotFoundException("Db collection [{$name}] does not exist");
        }

        switch ($this->_objectCollections[$name]->getLazyStrategy()) {
            case Objects::LAZY_STRATEGY_NONE:
                if (!$this->_objectCollections[$name]->isAllLoaded()) {
                    $this->_objectCollections[$name]->loadAllFromDB();
                }
                break;

            case Objects::LAZY_STRATEGY_KEY:
                break;

            case Objects::LAZY_STRATEGY_BATCH:
                break;

            case Objects::LAZY_STRATEGY_FULL_ON_ACCESS:
                break;

            default:
                throw new UnknownLazyStrategyException("Unknown lazy loading strategy for collection [{$name}]");
        }

        return $this->_dbItemCollections[$name];
    }

    /**
     * Configure collections (register object collections and setRepresent).
     *
     * Called from facade init() after createDb() and createRuntime().
     */
    abstract public function configure(): void;

    /**
     * Convert all collections to array.
     *
     * @return array<string, array<int|string, array<string, mixed>>> Collection name => items array
     * @throws LogicException When a represented collection class is misconfigured
     * @throws InvalidArgumentException When an object type does not match its collection
     */
    public function toArray(): array
    {
        return array_map(function ($collection) {
            return $collection->toArray();
        }, $this->_dbItemCollections);
    }
}
