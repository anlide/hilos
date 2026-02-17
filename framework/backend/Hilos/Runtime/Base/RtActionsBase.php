<?php

namespace Hilos\Hilos\Runtime\Base;

use Hilos\Hilos\Runtime\Exception\Actions\RtActionsCallbackNotSetException;
use Hilos\Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Hilos\Runtime\State\Item\RtState;
use Hilos\Hilos\Runtime\State\Collection\RtStates;
use Hilos\Hilos\TruthSource\RtTruthSourceRegistry;

/**
 * Base class for runtime actions (write operations for runtime collections).
 *
 * Write operations require truth source registration via RtTruthSourceRegistry.
 *
 * @property-read RtCollectionBase $collection
 */
abstract class RtActionsBase
{
    protected RtCollectionBase $collection;

    /** @var callable(RtState): RtItemBase|null */
    private $createRtItemCallback = null;

    /** @var callable(): void|null */
    private $clearCacheCallback = null;

    public function __construct(RtCollectionBase $collection)
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
    protected function createRtItemFromState(RtState &$state): RtItemBase
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
    }

    protected function removeStateFromCollection(string $id): void
    {
        $this->ensureCanWrite();
        $this->getStateCollection()->remove($id);
    }

    protected function clearAllStates(): void
    {
        $this->ensureCanWrite();
        $this->getStateCollection()->clear();
        $this->clearCollectionCache();
    }
}
