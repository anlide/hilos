<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Actions\Collection;

use Hilos\Constants\SignalConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\Sync\DTO\RtSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\Exception\Actions\RtActionsCallbackNotSetException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsItemClassException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Collection\RtStates;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Collection\RtCollection;
use Hilos\Runtime\View\Item\RtItem;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Core\Exception\InvalidArgumentException;

/**
 * RtActions - create, bulk, and collection-wide write operations for runtime collections.
 *
 * Write operations require truth source registration via RtTruthSourceRegistry.
 * One-item update/delete operations belong to the loaded RtItem actions.
 *
 * @template T of RtItem
 * @template TCollection of RtCollection = RtCollection
 * @template TStateCollection of RtStates = RtStates
 * @property-read TStateCollection $stateCollection Backing runtime state collection
 */
abstract class RtActions
{
    public const string stateCollection = 'stateCollection';

    /** @var TCollection Owning view collection (typed as {@see RtCollection} for the field declaration) */
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
     * @param string $name Property name
     * @return RtStates Backing state collection
     *
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws InvalidArgumentException When property is unknown
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            self::stateCollection => $this->getStateCollection(),
            default => throw new InvalidArgumentException("Unknown property: {$name}"),
        };
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
     * @param RtState $state State instance
     * @return T Concrete RtItem for this collection, bound by subclass `@extends`
     * @throws RtActionsCallbackNotSetException When createRtItemCallback is not set
     * @throws RtActionsItemClassException When the item factory returns a class the collection does not accept
     */
    protected function createRtItemFromState(RtState $state): RtItem
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
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
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
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    protected function ensureCanWrite(): void
    {
        $collectionName = $this->getCollectionName()
            ?? throw new RtActionsCollectionNameNullException(
                "Cannot ensure write: collection name is null"
            );
        RtTruthSourceRegistry::checkCanWrite($collectionName);
    }

    /**
     * Ensures one operation on one runtime state id is allowed.
     *
     * @param string $stateId Runtime state id
     * @param TruthSourceOperation $operation Operation the caller is about to perform
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When the row or the operation is not the caller's
     */
    protected function ensureCanWriteState(string $stateId, TruthSourceOperation $operation): void
    {
        $collectionName = $this->getCollectionName()
            ?? throw new RtActionsCollectionNameNullException(
                "Cannot ensure write: collection name is null"
            );
        RtTruthSourceRegistry::checkCanWriteState($collectionName, $stateId, $operation);
    }

    /**
     * Adds state to collection.
     *
     * The view cache and the outgoing sync are not touched here: the collection announces the
     * new membership itself, and the subscribers to that announcement do both. This road is only
     * one of the ways a row reaches the store, and it used to be the only one that remembered.
     *
     * @param RtState $state State instance to add
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws SourceChangeSubscriberException Whatever a subscriber to the collection's announcement raises
     */
    protected function addStateToCollection(RtState $state): void
    {
        $this->ensureCanWriteState($state->getId(), TruthSourceOperation::Add);
        $this->getStateCollection()->add($state);
    }

    /**
     * Apply diff to state and queue RT sync updated signal.
     *
     * Analogous to Object_::sync() for DB; the diff is known at call site.
     *
     * @param RtState $state State instance to apply diff to
     * @param array<string, mixed> $diff Changed fields and values
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     * @throws HilosException Whatever the concrete state's read of the diff raises
     */
    protected function applyDiffToState(RtState $state, array $diff): void
    {
        $this->ensureCanWriteState($state->getId(), TruthSourceOperation::Update);
        $state->applyDiff($diff);
        $this->queueRtSyncUpdated($state->getId(), $diff);
        $state->markRtSyncBaseline();
    }

    /**
     * Removes state from collection by ID.
     *
     * The previous row no longer has to be read here to be broadcast: the collection reads it
     * before dropping the key and puts it into its own announcement.
     *
     * @param string $id State ID to remove
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws SourceChangeSubscriberException Whatever a subscriber to the collection's announcement raises
     */
    protected function removeStateFromCollection(string $id): void
    {
        $this->ensureCanWriteState($id, TruthSourceOperation::Remove);
        $this->getStateCollection()->remove($id);
    }

    /**
     * Clears all states from collection and queues RT sync deleted for each.
     *
     * Every row is checked for removal before the first one is announced: a wipe that refused
     * halfway would leave the store cleared for some keys and announced for others.
     *
     * @throws RtActionsCallbackNotSetException When clear-cache callback is not configured
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    protected function clearAllStates(): void
    {
        $this->ensureCanWrite();
        $collectionName = $this->getCollectionName();
        $stateCollection = $this->getStateCollection();
        if ($collectionName !== null) {
            foreach ($stateCollection as $state) {
                $this->ensureCanWriteState($state->getId(), TruthSourceOperation::Remove);
            }
            foreach ($stateCollection as $state) {
                $this->queueRtSyncDeleted($state->getId(), $state->toArray());
            }
        }
        $stateCollection->clear();
        $this->clearCollectionCache();
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
            new RtSyncUpdatedSignalData(
                $collectionName,
                $stateId,
                $diff,
                ExecutionContext::currentAcceptKey(),
                ExecutionContext::currentRequestId(),
            ),
        );
    }

    /**
     * Queues RT sync deleted signal for broadcasting.
     *
     * Kept for the whole-collection wipe alone. A point removal is announced by the collection
     * itself and sent by a subscriber to that announcement; a wipe is deliberately not announced
     * row by row, because it travels its own road end to end and routing it through per-row
     * announcements would change what goes over the wire.
     *
     * @param string $stateId State ID
     * @param array<string, mixed> $row Previous runtime row data
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    private function queueRtSyncDeleted(string $stateId, array $row): void
    {
        $collectionName = $this->getCollectionName();
        if ($collectionName === null) {
            return;
        }
        Hilos::$sr?->queueRtSyncSignal(
            SignalConstants::RT_SYNC_DELETED,
            new RtSyncDeletedSignalData(
                $collectionName,
                $stateId,
                $row,
                ExecutionContext::currentAcceptKey(),
                ExecutionContext::currentRequestId(),
            ),
        );
    }
}
