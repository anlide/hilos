<?php

namespace Hilos\Runtime\Idea;

use Hilos\Exception\Runtime\Actions\IdeaRtActionsCallbackNotSetException;
use Hilos\Exception\Runtime\Actions\IdeaRtActionsCollectionNameNullException;
use Hilos\Exception\Runtime\Actions\IdeaRtActionsStateCollectionNullException;
use Hilos\Exception\Runtime\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\RtTruthSourceRegistry;
use Hilos\Runtime\State\RtState;
use Hilos\Runtime\State\RtStates;

/**
 * Base class for runtime Idea Actions
 *
 * IdeaRtActions provides write operations (mutations) for runtime collections
 * (analogous to IdeaActions for database).
 *
 * All write operations on runtime data must go through Actions.
 * IdeaRtCollection and IdeaRtItem are read-only.
 *
 * Write operations require truth source registration via RtTruthSourceRegistry.
 *
 * Usage:
 *   Idea::$rt->connections->actions->register($acceptKey, $userId);
 *   Idea::$rt->connections->actions->unregister($acceptKey);
 *
 * @property-read IdeaRtCollection $collection IdeaRtCollection instance this actions belong to
 */
abstract class IdeaRtActions
{
    /**
     * IdeaRtCollection instance this actions belong to
     *
     * @var IdeaRtCollection
     */
    protected IdeaRtCollection $collection;

    /**
     * Callback for creating IdeaRtItem from RtState
     *
     * @var callable(RtState): IdeaRtItem|null
     */
    private $createRtItemCallback = null;

    /**
     * Callback for clearing IdeaRtCollection cache
     *
     * @var callable(): void|null
     */
    private $clearCacheCallback = null;

    /**
     * Constructor
     *
     * @param IdeaRtCollection $collection IdeaRtCollection instance
     */
    public function __construct(IdeaRtCollection $collection)
    {
        $this->collection = $collection;
    }

    /**
     * Set callback for creating IdeaRtItem from RtState
     *
     * Called by IdeaRtCollection when Actions is created.
     *
     * @param callable(RtState): IdeaRtItem $callback Callback function
     */
    public function setCreateRtItemCallback(callable $callback): void
    {
        $this->createRtItemCallback = $callback;
    }

    /**
     * Set callback for clearing IdeaRtCollection cache
     *
     * Called by IdeaRtCollection when Actions is created.
     *
     * @param callable(): void $callback
     */
    public function setClearCacheCallback(callable $callback): void
    {
        $this->clearCacheCallback = $callback;
    }

    /**
     * Create IdeaRtItem from RtState using callback
     *
     * @param RtState $state RtState instance
     * @return IdeaRtItem
     * @throws IdeaRtActionsCallbackNotSetException If callback is not set
     */
    protected function createRtItemFromState(RtState &$state): IdeaRtItem
    {
        if ($this->createRtItemCallback === null) {
            throw new IdeaRtActionsCallbackNotSetException(
                "createRtItemCallback is not set. IdeaRtCollection must call setCreateRtItemCallback() when creating Actions."
            );
        }

        return ($this->createRtItemCallback)($state);
    }

    /**
     * Clear IdeaRtCollection cache via callback
     *
     * Used after mass-mutations (e.g. clear()) so collection doesn't return stale items.
     *
     * @throws IdeaRtActionsCallbackNotSetException If callback is not set
     */
    protected function clearCollectionCache(): void
    {
        if ($this->clearCacheCallback === null) {
            throw new IdeaRtActionsCallbackNotSetException(
                "clearCacheCallback is not set. IdeaRtCollection must call setClearCacheCallback() when creating Actions."
            );
        }

        ($this->clearCacheCallback)();
    }

    /**
     * Get state collection for this Actions
     *
     * @return ?RtStates State collection instance
     */
    protected function getStateCollection(): ?RtStates
    {
        return $this->collection->getStateCollection();
    }

    /**
     * Get collection name
     *
     * @return ?string Collection name or null if not set
     */
    protected function getCollectionName(): ?string
    {
        return $this->collection->getCollectionName();
    }

    /**
     * Ensure write operation is allowed
     *
     * Checks RtTruthSourceRegistry to verify that an agent has registered
     * as truth source for this collection.
     *
     * @throws IdeaRtActionsCollectionNameNullException If collection name is null
     * @throws RtTruthSourceWriteNotAllowedException If no truth source registered
     */
    protected function ensureCanWrite(): void
    {
        $collectionName = $this->getCollectionName();
        if ($collectionName === null) {
            throw new IdeaRtActionsCollectionNameNullException(
                "Cannot ensure write: collection name is null"
            );
        }

        RtTruthSourceRegistry::checkCanWrite($collectionName);
    }

    /**
     * Add state to collection
     *
     * @param RtState $state State instance to add
     * @throws IdeaRtActionsStateCollectionNullException If state collection is null
     * @throws IdeaRtActionsCollectionNameNullException If collection name is null
     * @throws RtTruthSourceWriteNotAllowedException If no truth source registered
     */
    protected function addStateToCollection(RtState $state): void
    {
        $this->ensureCanWrite();

        $stateCollection = $this->getStateCollection();
        if ($stateCollection === null) {
            throw new IdeaRtActionsStateCollectionNullException(
                "Cannot add state: state collection is null"
            );
        }

        $stateCollection->add($state);
    }

    /**
     * Remove state from collection by ID
     *
     * @param string $id State ID
     * @throws IdeaRtActionsStateCollectionNullException If state collection is null
     * @throws IdeaRtActionsCollectionNameNullException If collection name is null
     * @throws RtTruthSourceWriteNotAllowedException If no truth source registered
     */
    protected function removeStateFromCollection(string $id): void
    {
        $this->ensureCanWrite();

        $stateCollection = $this->getStateCollection();
        if ($stateCollection === null) {
            throw new IdeaRtActionsStateCollectionNullException(
                "Cannot remove state: state collection is null"
            );
        }

        $stateCollection->remove($id);
    }

    /**
     * Clear all states from collection
     *
     * @throws IdeaRtActionsStateCollectionNullException If state collection is null
     * @throws IdeaRtActionsCallbackNotSetException If callback is not set
     * @throws IdeaRtActionsCollectionNameNullException If collection name is null
     * @throws RtTruthSourceWriteNotAllowedException If no truth source registered
     */
    protected function clearAllStates(): void
    {
        $this->ensureCanWrite();

        $stateCollection = $this->getStateCollection();
        if ($stateCollection === null) {
            throw new IdeaRtActionsStateCollectionNullException(
                "Cannot clear states: state collection is null"
            );
        }

        $stateCollection->clear();
        $this->clearCollectionCache();
    }
}
