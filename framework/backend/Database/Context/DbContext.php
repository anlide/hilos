<?php

declare(strict_types=1);

namespace Hilos\Database\Context;

use Hilos\Core\Table\Actions\TableItemActions;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\Actions\Collection\DbActions;
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
