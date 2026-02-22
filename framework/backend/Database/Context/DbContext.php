<?php
namespace Hilos\Database\Context;

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
     * DB collections (wrappers around Object collections)
     * These are created automatically from Object collections.
     *
     * @var array<string, DbCollection>
     */
    protected array $_ideaCollections = [];

    /**
     * Constructor. Called from facade createDb().
     */
    public function __construct()
    {
    }

    /**
     * Public clone - prevent cloning.
     *
     * @throws CloneNotAllowedException
     */
    public function __clone(): void
    {
        throw new CloneNotAllowedException('DbContext cannot be cloned');
    }

    /**
     * Set representation for object collection.
     *
     * @param string $name Collection name (e.g., 'users')
     * @param string $ideaCollectionClass DB collection class name
     * @param ?string $actionsClass Collection actions class name (optional)
     * @param ?string $itemActionsClass Item actions class name (optional)
     * @throws ObjectCollectionNotFoundException
     */
    public function setRepresent(string $name, string $ideaCollectionClass, ?string $actionsClass = null, ?string $itemActionsClass = null): void
    {
        if (!isset($this->_objectCollections[$name])) {
            throw new ObjectCollectionNotFoundException(
                "Object collection [{$name}] not found in _objectCollections. Create it before calling setRepresent()."
            );
        }

        $objectCollection = $this->_objectCollections[$name];

        if (is_subclass_of($ideaCollectionClass, DbCollection::class)) {
            $ideaCollection = $ideaCollectionClass::init();
            $ideaCollection->setObjectCollection($objectCollection);
            if ($actionsClass !== null) {
                $ideaCollection->setActionsClass($actionsClass);
            }
            if ($itemActionsClass !== null) {
                $ideaCollection->setItemActionsClass($itemActionsClass);
            }
            $this->_ideaCollections[$name] = $ideaCollection;
        }
    }

    /**
     * Get object collection by name.
     *
     * @return ?Objects Object collection or null if not found
     */
    public function getObjectCollection(string $name): ?Objects
    {
        return $this->_objectCollections[$name] ?? null;
    }

    /**
     * Get DB collection by name.
     *
     * @throws CollectionNotFoundException
     * @throws UnknownLazyStrategyException
     * @throws DatabaseException
     */
    public function __get(string $name)
    {
        if (!isset($this->_ideaCollections[$name])) {
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

        return $this->_ideaCollections[$name];
    }

    /**
     * Configure collections (register object collections and setRepresent).
     * Called from facade init() after createDb() and createRuntime().
     */
    abstract public function configure(): void;

    /**
     * Convert all collections to array.
     *
     * @return array<string, array>
     */
    public function toArray(): array
    {
        return array_map(function ($collection) {
            return $collection->toArray();
        }, $this->_ideaCollections);
    }
}
