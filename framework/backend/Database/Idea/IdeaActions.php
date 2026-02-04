<?php

namespace Hilos\Database\Idea;

use Hilos\Database\Object\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Exception\DatabaseException;
use Hilos\Exception\Idea\Actions\IdeaActionsCallbackNotSetException;
use Hilos\Exception\Idea\Actions\IdeaActionsDuplicateIdException;
use Hilos\Exception\Idea\Actions\IdeaActionsObjectCollectionNullException;
use Hilos\Exception\Idea\Actions\IdeaActionsTableNameUndeterminedException;
use Hilos\Exception\Idea\Actions\IdeaActionsUnknownLazyStrategyException;
use Hilos\Exception\Idea\TruthSource\IdeaTruthSourceWriteNotAllowedException;

/**
 * Base class for Idea Actions
 * Provides write operations (mutations) for Idea collections
 *
 * Actions are used to perform write operations on collections.
 * Each IdeaCollection can have its own Actions class with collection-specific methods.
 *
 * Usage:
 *   $user = Idea::$db->users->actions->register($sessionToken);
 *   $event = Idea::$db->events->actions->add($type, $userId, $data);
 *
 * @property-read IdeaCollection $collection IdeaCollection instance this actions belong to
 */
abstract class IdeaActions
{
    /**
     * IdeaCollection instance this actions belong to
     * Type is declared via property-read in child classes
     *
     * @var IdeaCollection
     */
    protected IdeaCollection $collection;

    /**
     * Callback for creating IdeaItem from Object
     * Set by IdeaCollection via setCreateIdeaCallback()
     *
     * @var callable(Object_): IdeaItem|null
     */
    private $createIdeaCallback = null;

    /**
     * Callback for notifying IdeaCollection about mass changes (e.g. deleteAll()).
     * Set by IdeaCollection via setClearCacheCallback().
     *
     * @var callable(): void|null
     */
    private $clearCacheCallback = null;

    /**
     * Constructor
     *
     * @param IdeaCollection $collection IdeaCollection instance
     */
    public function __construct(IdeaCollection $collection)
    {
        $this->collection = $collection;
    }

    /**
     * Set callback for creating IdeaItem from Object
     * Called by IdeaCollection when Actions is created
     *
     * @param callable(Object_): IdeaItem $callback Callback function
     */
    public function setCreateIdeaCallback(callable $callback): void
    {
        $this->createIdeaCallback = $callback;
    }

    /**
     * Set callback for clearing IdeaCollection cache.
     * Called by IdeaCollection when Actions is created.
     *
     * @param callable(): void $callback
     */
    public function setClearCacheCallback(callable $callback): void
    {
        $this->clearCacheCallback = $callback;
    }

    /**
     * Create IdeaItem from Object using callback
     *
     * @param Object_ $object Object instance
     * @return IdeaItem
     * @throws IdeaActionsCallbackNotSetException If callback is not set
     */
    protected function createIdeaFromObject(Object_ &$object): IdeaItem
    {
        if ($this->createIdeaCallback === null) {
            throw new IdeaActionsCallbackNotSetException("createIdeaCallback is not set. IdeaCollection must call setCreateIdeaCallback() when creating Actions.");
        }

        return ($this->createIdeaCallback)($object);
    }

    /**
     * Clear IdeaCollection cache via callback.
     *
     * Used after mass-mutations on underlying ObjectCollection (e.g. deleteAll()),
     * so IdeaCollection doesn't return stale IdeaItem instances from its internal cache.
     *
     * @throws IdeaActionsCallbackNotSetException If callback is not set
     */
    protected function clearCollectionCache(): void
    {
        if ($this->clearCacheCallback === null) {
            throw new IdeaActionsCallbackNotSetException("clearCacheCallback is not set. IdeaCollection must call setClearCacheCallback() when creating Actions.");
        }

        ($this->clearCacheCallback)();
    }

    /**
     * Get ObjectCollection for this Actions
     * Returns reference to storage - modifications will affect the stored collection
     *
     * @return ?Objects ObjectCollection instance, or null for manual collections
     */
    protected function getObjectCollection(): ?Objects
    {
        return $this->collection->getObjectCollection();
    }

    /**
     * Get table name from ObjectCollection
     * Uses Objects::getTableName() or creates temporary object if collection is empty
     *
     * @return string Table name
     * @throws IdeaActionsObjectCollectionNullException If ObjectCollection is null
     * @throws IdeaActionsTableNameUndeterminedException If table name cannot be determined
     */
    protected function getTableName(): string
    {
        $objectCollection = $this->getObjectCollection();
        if ($objectCollection === null) {
            throw new IdeaActionsObjectCollectionNullException("Cannot get table name: ObjectCollection is null");
        }

        try {
            return $objectCollection->getTableName();
        } catch (\Exception $e) {
            // Collection is empty, try to create temporary object to get table name
            // This requires ObjectCollection to have a way to create temporary objects
            // For now, re-throw the exception - child classes should override if needed
            throw new IdeaActionsTableNameUndeterminedException("Cannot determine table name: collection is empty. Override getTableName() in Actions class if needed.", 0, $e);
        }
    }

    /**
     * Ensure write is allowed and data is loaded if needed
     * Checks TruthSourceRegistry and loads data based on lazy loading strategy
     *
     * @throws IdeaActionsObjectCollectionNullException If ObjectCollection is null
     * @throws IdeaActionsUnknownLazyStrategyException If unknown lazy loading strategy
     * @throws IdeaTruthSourceWriteNotAllowedException If write is not allowed
     * @throws IdeaActionsTableNameUndeterminedException If table name cannot be determined
     * @throws DatabaseException
     */
    protected function ensureCanWrite(): void
    {
        $objectCollection = $this->getObjectCollection();
        if ($objectCollection === null) {
            throw new IdeaActionsObjectCollectionNullException("Cannot ensure write: ObjectCollection is null");
        }

        switch ($objectCollection->getLazyStrategy()) {
            case Objects::LAZY_STRATEGY_NONE:
                // Check write permission
                $table = $this->getTableName();
                TruthSourceRegistry::checkCanWrite($table);

                // Load all data if not loaded yet
                if (!$objectCollection->isAllLoaded()) {
                    $objectCollection->loadAllFromDB();
                }
                break;

            case Objects::LAZY_STRATEGY_KEY:
                // TODO: Implement write check for KEY strategy
                break;

            case Objects::LAZY_STRATEGY_BATCH:
                // TODO: Implement write check for BATCH strategy
                break;

            case Objects::LAZY_STRATEGY_FULL_ON_ACCESS:
                // TODO: Implement write check for FULL_ON_ACCESS strategy
                break;

            default:
                // Unknown strategy - no action needed
                throw new IdeaActionsUnknownLazyStrategyException("Unknown lazy loading strategy for write check");
        }
    }

    /**
     * Add Object to ObjectCollection
     * Adds object to the global ObjectCollection storage
     * Checks for duplicate IDs and throws exception if object already exists
     *
     * @param Object_ $object Object instance to add
     * @throws IdeaActionsObjectCollectionNullException If ObjectCollection is null
     * @throws IdeaActionsDuplicateIdException If object with same ID already exists
     * @throws DatabaseException
     * @throws IdeaActionsTableNameUndeterminedException
     */
    protected function addObjectToCollection(Object_ &$object): void
    {
        $objectCollection = $this->getObjectCollection();
        if ($objectCollection === null) {
            throw new IdeaActionsObjectCollectionNullException("Cannot add object: ObjectCollection is null");
        }

        // Get ID string (supports composite keys)
        $idString = $object->getIdString();

        // Check if object with this ID already exists
        if (isset($objectCollection[$idString])) {
            $table = $this->getTableName();
            throw new IdeaActionsDuplicateIdException("Cannot add object to collection: object with ID '{$idString}' already exists in table '{$table}'");
        }

        $objectCollection[$idString] = $object;
    }
}
