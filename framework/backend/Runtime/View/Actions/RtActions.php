<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Actions;

use Hilos\Constants\SignalConstants;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Hilos;
use Hilos\Runtime\Exception\Actions\RtActionsCallbackNotSetException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Collection\RtStates;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Collection\RtCollection;
use Hilos\Runtime\View\Item\RtItem;
use Hilos\TruthSource\RtTruthSourceRegistry;

/**
 * RtActions - write operations for runtime collection.
 *
 * Write operations require truth source registration via RtTruthSourceRegistry.
 * All modifications to runtime data must go through this class.
 *
 * @template T of RtItem
 * @property-read RtCollection $collection
 */
abstract class RtActions
{
    /** @var RtCollection Rt collection instance for write operations */
    protected RtCollection $collection;

    /** @var ?callable(RtState): RtItem Callback to create RtItem from RtState */
    private $createRtItemCallback = null;

    /** @var ?callable(): void Callback to clear collection cache */
    private $clearCacheCallback = null;

    /**
     * Creates RtActions instance for the given collection.
     *
     * @param RtCollection $collection Rt collection to operate on
     */
    public function __construct(RtCollection $collection)
    {
        $this->collection = $collection;
    }

    /**
     * Sets callback for creating RtItem from RtState (called by RtCollection).
     *
     * @param callable(RtState): RtItem $callback Factory callback
     */
    public function setCreateRtItemCallback(callable $callback): void
    {
        $this->createRtItemCallback = $callback;
    }

    /**
     * Sets callback to clear collection cache after state changes.
     *
     * @param callable(): void $callback Clear cache callback
     */
    public function setClearCacheCallback(callable $callback): void
    {
        $this->clearCacheCallback = $callback;
    }

    /**
     * Creates RtItem from RtState via registered callback.
     *
     * @param RtState $state State instance (reference)
     * @return T RtItem wrapper for the state
     * @throws RtActionsCallbackNotSetException When createRtItemCallback is not set
     */
    protected function createRtItemFromState(RtState &$state): RtItem
    {
        if ($this->createRtItemCallback === null) {
            throw new RtActionsCallbackNotSetException(
                "createRtItemCallback is not set. RtCollection must call setCreateRtItemCallback() when creating Actions."
            );
        }
        return ($this->createRtItemCallback)($state);
    }

    /**
     * Clears RtCollection cache via registered callback.
     *
     * @throws RtActionsCallbackNotSetException When clearCacheCallback is not set
     */
    protected function clearCollectionCache(): void
    {
        if ($this->clearCacheCallback === null) {
            throw new RtActionsCallbackNotSetException(
                "clearCacheCallback is not set. RtCollection must call setClearCacheCallback() when creating Actions."
            );
        }
        ($this->clearCacheCallback)();
    }

    /**
     * Returns underlying state collection.
     *
     * @return RtStates State collection instance
     * @throws RtActionsStateCollectionNullException When state collection is null
     */
    protected function getStateCollection(): RtStates
    {
        return $this->collection->getStateCollection();
    }

    /**
     * Returns collection name (null if not set).
     *
     * @return ?string Collection name or null
     */
    protected function getCollectionName(): ?string
    {
        return $this->collection->getCollectionName();
    }

    /**
     * Ensures write is allowed (collection name set and truth source permits).
     *
     * @throws RtActionsCollectionNameNullException When collection name is null.
     * @throws RtTruthSourceWriteNotAllowedException When truth source does not allow write.
     */
    protected function ensureCanWrite(): void
    {
        $collectionName = $this->getCollectionName();
        if ($collectionName === null) {
            throw new RtActionsCollectionNameNullException(
                "Cannot ensure write: collection name is null"
            );
        }
        RtTruthSourceRegistry::checkCanWrite($collectionName);
    }

    /**
     * Adds state to collection and queues RT sync created signal.
     *
     * @param RtState $state State instance to add
     */
    protected function addStateToCollection(RtState $state): void
    {
        $this->ensureCanWrite();
        $this->getStateCollection()->add($state);
        $this->queueRtSyncCreated($state->getId(), $state->toArray());
    }

    /**
     * Apply diff to state and queue RT sync updated signal.
     *
     * Analogous to Object_::sync() for DB — the diff is known at call site.
     *
     * @param RtState $state State instance to apply diff to
     * @param array<string, mixed> $diff Changed fields and values
     */
    protected function applyDiffToState(RtState $state, array $diff): void
    {
        $this->ensureCanWrite();
        $state->applyDiff($diff);
        $this->queueRtSyncUpdated($state->getId(), $diff);
    }

    /**
     * Removes state from collection by ID and queues RT sync deleted signal.
     *
     * @param string $id State ID to remove
     */
    protected function removeStateFromCollection(string $id): void
    {
        $this->ensureCanWrite();
        $this->getStateCollection()->remove($id);
        $this->queueRtSyncDeleted($id);
    }

    /**
     * Clears all states from collection and queues RT sync deleted for each.
     */
    protected function clearAllStates(): void
    {
        $this->ensureCanWrite();
        $collectionName = $this->getCollectionName();
        $stateCollection = $this->getStateCollection();
        if ($collectionName !== null) {
            foreach ($stateCollection as $state) {
                $this->queueRtSyncDeleted($state->getId());
            }
        }
        $stateCollection->clear();
        $this->clearCollectionCache();
    }

    /**
     * Queues RT sync created signal for broadcasting.
     *
     * @param string $stateId State ID
     * @param array<string, mixed> $row Full state data
     */
    private function queueRtSyncCreated(string $stateId, array $row): void
    {
        $collectionName = $this->getCollectionName();
        if ($collectionName === null) {
            return;
        }
        Hilos::$sr?->queueRtSyncSignal(
            SignalConstants::RT_SYNC_CREATED,
            new RtSyncCreatedSignalData($collectionName, $stateId, $row),
        );
    }

    /**
     * Queues RT sync updated signal for broadcasting.
     *
     * @param string $stateId State ID
     * @param array<string, mixed> $diff Changed fields and values
     */
    private function queueRtSyncUpdated(string $stateId, array $diff): void
    {
        $collectionName = $this->getCollectionName();
        if ($collectionName === null) {
            return;
        }
        Hilos::$sr?->queueRtSyncSignal(
            SignalConstants::RT_SYNC_UPDATED,
            new RtSyncUpdatedSignalData($collectionName, $stateId, $diff),
        );
    }

    /**
     * Queues RT sync deleted signal for broadcasting.
     *
     * @param string $stateId State ID
     */
    private function queueRtSyncDeleted(string $stateId): void
    {
        $collectionName = $this->getCollectionName();
        if ($collectionName === null) {
            return;
        }
        Hilos::$sr?->queueRtSyncSignal(
            SignalConstants::RT_SYNC_DELETED,
            new RtSyncDeletedSignalData($collectionName, $stateId),
        );
    }
}
