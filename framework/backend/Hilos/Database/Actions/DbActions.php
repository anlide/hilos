<?php

declare(strict_types=1);

namespace Hilos\Hilos\Database\Actions;

use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Hilos\Database\Actions\Exception\CallbackNotSetException;
use Hilos\Hilos\Database\Actions\Exception\DuplicateIdException;
use Hilos\Hilos\Database\Actions\Exception\ObjectCollectionNullException;
use Hilos\Hilos\Database\Actions\Exception\TableNameUndeterminedException;
use Hilos\Hilos\Database\Actions\Exception\UnknownLazyStrategyException;
use Hilos\Hilos\Database\Collection\DbCollection;
use Hilos\Hilos\Database\Item\DbItem;

/**
 * Base class for Db Actions (write operations for Db collections).
 * Actions are used to perform write operations on collections.
 * Each DbCollection can have its own Actions class with collection-specific methods.
 *
 * Usage:
 *   $user = Hilos::$db->users->actions->register($sessionToken);
 *   $event = Hilos::$db->events->actions->add($type, $userId, $data);
 *
 * @template T of DbItem
 * @template TObjectCollection of Objects
 * @property-read DbCollection<T, TObjectCollection> $collection DbCollection instance this actions belong to
 * @property-read TObjectCollection $objectCollection ObjectCollection shortcut (via __get)
 */
abstract class DbActions
{
    /**
     * DbCollection instance this actions belong to
     * Type is declared via property-read in child classes
     *
     * @var DbCollection<T, TObjectCollection>
     */
    protected DbCollection $collection;

    /**
     * Callback for creating DbItem from Object
     * Set by DbCollection via setCreateIdeaCallback()
     *
     * @var callable(Object_): DbItem|null
     */
    private $createIdeaCallback = null;

    /**
     * Callback for notifying DbCollection about mass changes (e.g. deleteAll()).
     * Set by DbCollection via setClearCacheCallback().
     *
     * @var callable(): void|null
     */
    private $clearCacheCallback = null;

    /**
     * Constructor
     *
     * @param DbCollection<T, TObjectCollection> $collection DbCollection instance
     */
    public function __construct(DbCollection $collection)
    {
        $this->collection = $collection;
    }

    /**
     * Magic getter for objectCollection shortcut.
     *
     * @return TObjectCollection
     * @throws ObjectCollectionNullException If ObjectCollection is null (manual collection)
     */
    public function __get(string $name): mixed
    {
        if ($name === 'objectCollection') {
            $objectCollection = $this->getObjectCollection();
            if ($objectCollection === null) {
                throw new ObjectCollectionNullException("ObjectCollection is null (manual collection)");
            }
            return $objectCollection;
        }
        throw new \InvalidArgumentException("Unknown property: {$name}");
    }

    /**
     * Set callback for creating DbItem from Object
     * Called by DbCollection when Actions is created
     *
     * @param callable(Object_): DbItem $callback Callback function
     */
    public function setCreateIdeaCallback(callable $callback): void
    {
        $this->createIdeaCallback = $callback;
    }

    /**
     * Set callback for clearing DbCollection cache.
     * Called by DbCollection when Actions is created.
     *
     * @param callable(): void $callback
     */
    public function setClearCacheCallback(callable $callback): void
    {
        $this->clearCacheCallback = $callback;
    }

    /**
     * Create DbItem from Object using callback
     *
     * @param Object_ $object Object instance
     * @return T Db item (subtype of DbItem, bound in child class)
     * @throws CallbackNotSetException If callback is not set
     */
    protected function createIdeaFromObject(Object_ &$object): DbItem
    {
        if ($this->createIdeaCallback === null) {
            throw new CallbackNotSetException("createIdeaCallback is not set. DbCollection must call setCreateIdeaCallback() when creating Actions.");
        }
        return ($this->createIdeaCallback)($object);
    }

    /**
     * Clear DbCollection cache via callback.
     * Used after mass-mutations on underlying ObjectCollection (e.g. deleteAll()),
     * so DbCollection doesn't return stale DbItem instances from its internal cache.
     *
     * @throws CallbackNotSetException If callback is not set
     */
    protected function clearCollectionCache(): void
    {
        if ($this->clearCacheCallback === null) {
            throw new CallbackNotSetException("clearCacheCallback is not set. DbCollection must call setClearCacheCallback() when creating Actions.");
        }
        ($this->clearCacheCallback)();
    }

    /**
     * Get ObjectCollection for this Actions
     * Returns reference to storage - modifications will affect the stored collection
     *
     * @return ?TObjectCollection ObjectCollection instance, or null for manual collections
     */
    protected function getObjectCollection(): ?Objects
    {
        /** @var ?TObjectCollection $objectCollection */
        $objectCollection = $this->collection->getObjectCollection();
        return $objectCollection;
    }

    /**
     * Get table name from ObjectCollection
     * Uses Objects::getTableName() or creates temporary object if collection is empty
     *
     * @return string Table name
     * @throws TableNameUndeterminedException If table name cannot be determined
     */
    protected function getTableName(): string
    {
        try {
            return $this->objectCollection->getTableName();
        } catch (\Exception $e) {
            throw new TableNameUndeterminedException("Cannot determine table name: collection is empty. Override getTableName() in Actions class if needed.", 0, $e);
        }
    }

    /**
     * Ensure write is allowed and data is loaded if needed
     * Checks TruthSourceRegistry and loads data based on lazy loading strategy
     *
     * @throws ObjectCollectionNullException If ObjectCollection is null
     * @throws UnknownLazyStrategyException If unknown lazy loading strategy
     * @throws WriteNotAllowedException If write is not allowed
     * @throws DatabaseException
     */
    protected function ensureCanWrite(): void
    {
        $objectCollection = $this->objectCollection;

        switch ($objectCollection->getLazyStrategy()) {
            case Objects::LAZY_STRATEGY_NONE:
                $collectionKey = $objectCollection->getCollectionKey();
                TruthSourceRegistry::checkCanWrite($collectionKey);
                if (!$objectCollection->isAllLoaded()) {
                    $objectCollection->loadAllFromDB();
                }
                break;

            case Objects::LAZY_STRATEGY_KEY:
                break;

            case Objects::LAZY_STRATEGY_BATCH:
                break;

            case Objects::LAZY_STRATEGY_FULL_ON_ACCESS:
                break;

            default:
                throw new UnknownLazyStrategyException("Unknown lazy loading strategy for write check");
        }
    }

    /**
     * Add Object to ObjectCollection
     * Adds object to the global ObjectCollection storage
     * Checks for duplicate IDs and throws exception if object already exists
     *
     * @param Object_ $object Object instance to add
     * @throws ObjectCollectionNullException If ObjectCollection is null
     * @throws DuplicateIdException If object with same ID already exists
     * @throws DatabaseException
     * @throws TableNameUndeterminedException
     */
    protected function addObjectToCollection(Object_ $object): void
    {
        $idString = $object->getIdString();
        if (isset($this->objectCollection[$idString])) {
            $table = $this->getTableName();
            throw new DuplicateIdException("Cannot add object to collection: object with ID '{$idString}' already exists in table '{$table}'");
        }
        $this->objectCollection[$idString] = $object;
    }
}
