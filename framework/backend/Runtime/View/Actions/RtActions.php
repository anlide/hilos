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
 * @property-read RtCollection $collection
 */
abstract class RtActions
{
    protected RtCollection $collection;

    /** @var callable(RtState): RtItem|null */
    private $createRtItemCallback = null;

    /** @var callable(): void|null */
    private $clearCacheCallback = null;

    public function __construct(RtCollection $collection)
    {
        $this->collection = $collection;
    }

    public function setCreateRtItemCallback(callable $callback): void
    {
        $this->createRtItemCallback = $callback;
    }

    public function setClearCacheCallback(callable $callback): void
    {
        $this->clearCacheCallback = $callback;
    }

    /** @throws RtActionsCallbackNotSetException */
    protected function createRtItemFromState(RtState &$state): RtItem
    {
        if ($this->createRtItemCallback === null) {
            throw new RtActionsCallbackNotSetException(
                "createRtItemCallback is not set. RtCollection must call setCreateRtItemCallback() when creating Actions."
            );
        }
        return ($this->createRtItemCallback)($state);
    }

    /** @throws RtActionsCallbackNotSetException */
    protected function clearCollectionCache(): void
    {
        if ($this->clearCacheCallback === null) {
            throw new RtActionsCallbackNotSetException(
                "clearCacheCallback is not set. RtCollection must call setClearCacheCallback() when creating Actions."
            );
        }
        ($this->clearCacheCallback)();
    }

    /** @throws RtActionsStateCollectionNullException */
    protected function getStateCollection(): RtStates
    {
        return $this->collection->getStateCollection();
    }

    protected function getCollectionName(): ?string
    {
        return $this->collection->getCollectionName();
    }

    /** @throws RtActionsCollectionNameNullException */
    /** @throws RtTruthSourceWriteNotAllowedException */
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

    protected function addStateToCollection(RtState $state): void
    {
        $this->ensureCanWrite();
        $this->getStateCollection()->add($state);
        $this->queueRtSyncCreated($state->getId(), $state->toArray());
    }

    /**
     * Apply diff to state and queue RT sync updated signal.
     * Analogous to Object_::sync() for DB — the diff is known at call site.
     */
    protected function applyDiffToState(RtState $state, array $diff): void
    {
        $this->ensureCanWrite();
        $state->applyDiff($diff);
        $this->queueRtSyncUpdated($state->getId(), $diff);
    }

    protected function removeStateFromCollection(string $id): void
    {
        $this->ensureCanWrite();
        $this->getStateCollection()->remove($id);
        $this->queueRtSyncDeleted($id);
    }

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
