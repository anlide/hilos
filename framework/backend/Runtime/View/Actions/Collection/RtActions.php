<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Actions\Collection;

use Hilos\Constants\SignalConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\Sync\DTO\RtSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Database;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\Exception\Actions\RtActionsCallbackNotSetException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsItemClassException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\RtWriteBackpressureException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\Ordinary\RtOrdinaryAdmission;
use Hilos\Runtime\RtMutation;
use Hilos\Runtime\RtRowValues;
use Hilos\Runtime\State\Collection\RtStates;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Collection\RtCollection;
use Hilos\Runtime\View\Item\RtItem;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Core\Exception\InvalidArgumentException;
use LogicException;

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
     * @throws LogicException When a project item factory rejects its returned item
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
     * The door of an operation over the whole collection: only a claim over every row passes it,
     * so a claim over a set is refused here exactly as a claim over named rows is.
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
     * @param list<string> $setKeys Set keys the write touches, each once; empty for a row outside every set
     * @param TruthSourceOperation $operation Operation the caller is about to perform
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When the row or the operation is not the caller's
     */
    protected function ensureCanWriteState(string $stateId, array $setKeys, TruthSourceOperation $operation): void
    {
        $collectionName = $this->getCollectionName()
            ?? throw new RtActionsCollectionNameNullException(
                "Cannot ensure write: collection name is null"
            );
        RtTruthSourceRegistry::checkCanWriteState($collectionName, $stateId, $setKeys, $operation);
    }

    /**
     * Adds state to collection.
     *
     * The view cache and the outgoing sync are not touched here: the collection announces the
     * new membership itself, and the subscribers to that announcement do both. This road is only
     * one of the ways a row reaches the store, and it used to be the only one that remembered.
     *
     * This is the door that creates a runtime row: it asks for {@see TruthSourceOperation::Add} and
     * for the set the new row is born in, so a claim over a set brings into being rows of its own
     * set alone.
     *
     * The creation is admitted on the source's ordinary FIFO with the right, before the row is
     * in the store: a full FIFO refuses it while nothing has changed ({@see RtOrdinaryAdmission}).
     *
     * @param RtState $state State instance to add
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws RtWriteBackpressureException When the source's ordinary FIFO has no room; nothing was written
     * @throws SourceChangeSubscriberException Whatever a subscriber to the collection's announcement raises
     * @throws HilosException Whatever preparing the row's immutable operation raises
     */
    protected function addStateToCollection(RtState $state): void
    {
        $this->ensureCanWriteState($state->getId(), $state->touchedSetKeys(), TruthSourceOperation::Add);
        $stateCollection = $this->getStateCollection();
        $admission = RtOrdinaryAdmission::reserve(1);
        $admission->seal([
            RtOrdinaryAdmission::operation(TableMutationType::Create, $this->requireCollectionName(), null, $state),
        ]);
        try {
            $stateCollection->add($state);
        } finally {
            $admission->settle();
        }
    }

    /**
     * Apply diff to state and queue RT sync updated signal.
     *
     * Analogous to Object_::sync() for DB; the diff is known at call site.
     *
     * The check here is the only one the write gets - no {@see RtState::sync()} follows it - so the
     * set keys are counted with the diff, and a move to another set is caught before it is applied.
     *
     * A row with a binding takes the whole diff on its draft first ({@see RtState::draftRow()}): a
     * refused field publishes no field at all. The update is then prepared as one immutable
     * operation whose changed values and previous values the frame carries as they are, admitted
     * on the source's ordinary FIFO, and the draft is published in one swap. A diff that changes
     * nothing reserves, publishes and sends nothing - a caller republishing a whole snapshot pays
     * for the fields that moved alone. A legacy-only row keeps writing field by field, admitted
     * the same way.
     *
     * Under an open transaction the frame leaves at the commit, and the write leaves a step for the
     * rollback: the previous values go back through the same applyDiff() door, and the baseline
     * is reset to them.
     *
     * @param RtState $state State instance to apply diff to
     * @param array<string, mixed> $diff Changed fields and values
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws RtWriteBackpressureException When the source's ordinary FIFO has no room; nothing was written
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     * @throws HilosException Whatever the concrete state's read of the diff raises
     */
    protected function applyDiffToState(RtState $state, array $diff): void
    {
        $this->ensureCanWriteState($state->getId(), $state->touchedSetKeys($diff), TruthSourceOperation::Update);
        $collectionName = $this->requireCollectionName();
        if (!$state->hasRowBinding()) {
            $this->applyDiffToLegacyState($state, $diff);

            return;
        }
        $draft = $state->draftRow();
        $draft->applyDiff($diff);
        $mutation = RtOrdinaryAdmission::operation(TableMutationType::Update, $collectionName, $state, $draft);
        if (!$mutation instanceof RtMutation || $mutation->values()->isEmptyMap()) {
            return;
        }
        $admission = RtOrdinaryAdmission::reserve(1);
        $admission->seal([$mutation]);
        try {
            $previous = $mutation->previous();
            Database::onRollback(static function () use ($state, $previous): void {
                $state->applyDiff(RtRowValues::nativeMap($previous));
                $state->markRtSyncBaseline();
            });
            $state->publishDraft($draft);
            $this->queueRtSyncUpdated($state->getId(), $mutation->values(), $previous);
            $state->markRtSyncBaseline();
        } finally {
            $admission->settle();
        }
        RtOrdinaryAdmission::supersedeOwed($collectionName, $state->getId());
    }

    /**
     * Removes state from collection by ID.
     *
     * The previous row no longer has to be read here to be broadcast: the collection reads it
     * before dropping the key and puts it into its own announcement.
     *
     * The write is judged by the set the stored row stands in; an id with no row behind it names
     * no set. The removal is admitted on the source's ordinary FIFO before the key leaves the store.
     *
     * @param string $id State ID to remove
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws RtWriteBackpressureException When the source's ordinary FIFO has no room; nothing was written
     * @throws SourceChangeSubscriberException Whatever a subscriber to the collection's announcement raises
     * @throws HilosException Whatever preparing the row's immutable operation raises
     */
    protected function removeStateFromCollection(string $id): void
    {
        $stateCollection = $this->getStateCollection();
        $held = $stateCollection->get($id);
        $this->ensureCanWriteState($id, $held?->touchedSetKeys() ?? [], TruthSourceOperation::Remove);
        $admission = RtOrdinaryAdmission::reserve(1);
        $admission->seal([$held === null
            ? []
            : RtOrdinaryAdmission::operation(TableMutationType::Delete, $this->requireCollectionName(), $held, null)]);
        try {
            $stateCollection->remove($id);
        } finally {
            $admission->settle();
        }
    }

    /**
     * Clears all states from collection and queues RT sync deleted for each.
     *
     * Every row is checked for removal before the first one is announced: a wipe that refused
     * halfway would leave the store cleared for some keys and announced for others. The wipe is
     * then admitted as one compound of every row on the source's ordinary FIFO, before the first
     * row is announced; a wipe longer than the whole quota takes the exclusive large slot.
     *
     * @throws RtActionsCallbackNotSetException When clear-cache callback is not configured
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws RtWriteBackpressureException When the source's ordinary FIFO has no room; nothing was written
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     * @throws HilosException Whatever preparing a row's immutable operation raises
     */
    protected function clearAllStates(): void
    {
        $this->ensureCanWrite();
        $collectionName = $this->getCollectionName();
        $stateCollection = $this->getStateCollection();
        $admission = null;
        if ($collectionName !== null) {
            $operations = [];
            foreach ($stateCollection as $state) {
                $this->ensureCanWriteState($state->getId(), $state->touchedSetKeys(), TruthSourceOperation::Remove);
            }
            if (count($stateCollection) > 0) {
                $admission = RtOrdinaryAdmission::reserve(count($stateCollection));
                foreach ($stateCollection as $state) {
                    $operations[] = RtOrdinaryAdmission::operation(TableMutationType::Delete, $collectionName, $state, null);
                }
                $admission->seal($operations);
            }
        }
        try {
            if ($collectionName !== null) {
                foreach ($stateCollection as $state) {
                    $this->queueRtSyncDeleted($state->getId(), $state->carriedRow());
                }
            }
            $stateCollection->clear();
            $this->clearCollectionCache();
        } finally {
            $admission?->settle();
        }
    }

    /**
     * Removes every row as mandatory cleanup - the rows a stopping owner leaves behind - each from
     * the bounded service reserve of the source's FIFO and never refused for want of room: a row
     * the reserve cannot take now is owed by its identity and retried as the FIFO frees
     * ({@see RtOrdinaryAdmission::removeAsCleanup()}). The right to remove is judged for every row
     * before the first one goes.
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws SourceChangeSubscriberException Whatever a subscriber to the collection's announcement raises
     * @throws HilosException Whatever preparing a row's immutable operation raises
     * @throws InvalidArgumentException When a retirement allowance of the hand-off is invalid
     */
    protected function removeAllStatesAsCleanup(): void
    {
        $this->ensureCanWrite();
        $collectionName = $this->requireCollectionName();
        $stateCollection = $this->getStateCollection();
        $rows = [];
        foreach ($stateCollection as $state) {
            $this->ensureCanWriteState($state->getId(), $state->touchedSetKeys(), TruthSourceOperation::Remove);
            $rows[] = $state;
        }
        foreach ($rows as $state) {
            RtOrdinaryAdmission::removeAsCleanup($stateCollection, $collectionName, $state);
        }
        $this->clearCollectionCache();
    }

    /**
     * The field-by-field write of a legacy-only row, admitted on the source's ordinary FIFO with its
     * native diff before the first field is written.
     *
     * @param RtState $state Legacy-only state
     * @param array<string, mixed> $diff Changed fields and values
     * @throws RtWriteBackpressureException When the source's ordinary FIFO has no room; nothing was written
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     * @throws HilosException Whatever the concrete state's read of the diff raises
     */
    private function applyDiffToLegacyState(RtState $state, array $diff): void
    {
        $admission = RtOrdinaryAdmission::reserve(1);
        $admission->seal([$diff]);
        try {
            $previous = array_intersect_key($state->toArray(), $diff);
            Database::onRollback(static function () use ($state, $previous): void {
                $state->applyDiff($previous);
                $state->markRtSyncBaseline();
            });
            $state->applyDiff($diff);
            $this->queueRtSyncUpdated($state->getId(), $diff, $previous);
            $state->markRtSyncBaseline();
        } finally {
            $admission->settle();
        }
        RtOrdinaryAdmission::supersedeOwed($this->requireCollectionName(), $state->getId());
    }

    /**
     * @return string Collection key a prepared operation and its frame name
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     */
    private function requireCollectionName(): string
    {
        return $this->getCollectionName()
            ?? throw new RtActionsCollectionNameNullException('Cannot prepare a write: collection name is null');
    }

    /**
     * Queues RT sync updated signal for broadcasting.
     *
     * @param string $stateId State ID
     * @param array<string, mixed>|RtRowValues $diff Changed fields and values
     * @param array<string, mixed>|RtRowValues $previous Previous values of changed fields
     */
    private function queueRtSyncUpdated(string $stateId, array|RtRowValues $diff, array|RtRowValues $previous): void
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
                previous: $previous,
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
     * @param array<string, mixed>|RtRowValues $row Previous runtime row data, segmented for a bound row
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    private function queueRtSyncDeleted(string $stateId, array|RtRowValues $row): void
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
