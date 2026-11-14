<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Actions\Item;

use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Actions\Item\DbActions;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Item\RtItemParentCollectionNullException;
use Hilos\Runtime\Exception\RtWriteBackpressureException;
use Hilos\Runtime\Exception\State\RtRowCodecNotSupportedException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\Ordinary\RtOrdinaryAdmission;
use Hilos\Runtime\RtMutation;
use Hilos\Runtime\RtRowValues;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Collection\RtCollection;
use Hilos\Runtime\View\Item\RtItem;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\HilosException;

/**
 * Base class for Rt item actions (write operations for a single RtItem).
 *
 * @template T of RtItem
 * @template TState of RtState
 * @property-read TState $state Backing runtime state (same pattern as {@see DbActions}::$object)
 */
abstract class RtActions
{
    public const string state = 'state';

    /** @var T RtItem instance these actions belong to */
    protected RtItem $item;

    /**
     * @param T $item RtItem instance
     */
    public function __construct(RtItem $item)
    {
        $this->item = $item;
    }

    /**
     * @param string $name Property name (state only)
     * @return RtState Backing state
     *
     * @throws InvalidArgumentException When property is unknown
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            self::state => $this->item->getState(),
            default => throw new InvalidArgumentException("Unknown property: {$name}"),
        };
    }

    /**
     * Parent collection (truth source name, sync, view cache).
     *
     * @throws RtItemParentCollectionNullException When item was not wired via RtCollection
     */
    protected function getRtCollection(): RtCollection
    {
        return $this->item->getCollection()
            ?? throw new RtItemParentCollectionNullException(
                'RtItem has no parent collection; item actions require RtCollection::attachItemToCollection().'
            );
    }

    /**
     * Ensures the caller may perform one operation on the row this item wraps.
     *
     * Defaults to editing because that is what an item action does: the row is already there,
     * held by this very item, and the ones that instead drop it name the operation themselves.
     *
     * The write is judged by the set the row stands in now ({@see RtState::touchedSetKeys()}):
     * callers ask this door before they assign the fields, so a move to another set is not in the
     * row yet here, and the {@see RtState::sync()} that follows the assignments asks again and
     * catches it.
     *
     * This door judges the right only. A write whose fields are assigned before its sync has no
     * values to admit on the ordinary FIFO here, and its sync may not refuse; the doors that know
     * their values first - {@see self::applyDiffWithSync()}, {@see self::remove()} - are admitted.
     *
     * @param TruthSourceOperation $operation Operation the caller is about to perform
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When the row or the operation is not the caller's
     */
    protected function ensureCanWrite(TruthSourceOperation $operation = TruthSourceOperation::Update): void
    {
        RtTruthSourceRegistry::checkCanWriteState(
            $this->getRtCollectionKey(),
            $this->state->getId(),
            $this->state->touchedSetKeys(),
            $operation,
        );
    }

    /**
     * Collection key used for truth-source checks and RT sync.
     *
     * Attached collection items use the parent collection name. Standalone items use their state class sync key.
     *
     * @return string Runtime collection key used for truth-source checks
     * @throws RtActionsCollectionNameNullException When neither source provides a key
     */
    private function getRtCollectionKey(): string
    {
        $collectionName = $this->item->getCollection()?->getCollectionName()
            ?? $this->state::getRtCollectionKey();

        return $collectionName !== ''
            ? $collectionName
            : throw new RtActionsCollectionNameNullException(
                'Cannot ensure write: collection name is null'
            );
    }

    /**
     * Queue RT_SYNC_UPDATED for pending state changes and clear the view cache.
     * Call after mutating `state` assignments or applying diffs.
     *
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    protected function sync(): void
    {
        $this->state->sync();
        $this->item->getCollection()?->clearCache();
    }

    /**
     * Delete the current runtime item.
     *
     * Dropping the key from the store is the whole of it now: the collection announces the lost
     * membership, and the subscribers to that announcement drop the cached wrapper and broadcast
     * the previous row. The wholesale cache clear this used to do is gone with them - it ended
     * any walk that drove the removal at its first step, and only one key ever needed dropping.
     *
     * The removal is admitted on the source's ordinary FIFO, with the right, before the key leaves the store.
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtItemParentCollectionNullException When item is not attached to a collection
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws RtWriteBackpressureException When the source's ordinary FIFO has no room; nothing was written
     * @throws SourceChangeSubscriberException Whatever a subscriber to the collection's announcement raises
     * @throws HilosException Whatever preparing the row's immutable operation raises
     */
    protected function remove(): void
    {
        $this->ensureCanWrite(TruthSourceOperation::Remove);
        $stateCollection = $this->getRtCollection()->getStateCollection();
        $admission = RtOrdinaryAdmission::reserve(1);
        $admission->seal([RtOrdinaryAdmission::operation(TableMutationType::Delete, $this->getRtCollectionKey(), $this->state, null)]);
        try {
            $stateCollection->remove($this->state->getId());
        } finally {
            $admission->settle();
        }
    }

    /**
     * Delete the current runtime item as mandatory cleanup: the row of a socket or an owner that
     * is gone must go, whatever the source's ordinary FIFO holds.
     *
     * The right is judged as for {@see self::remove()}. The removal then takes the bounded service
     * reserve of the source's FIFO rather than its ordinary room, and never refuses for want of
     * it: a removal the reserve cannot take now is owed by this row's identity and retried as the
     * FIFO frees, against the collection as it then stands ({@see RtOrdinaryAdmission::removeAsCleanup()}).
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtItemParentCollectionNullException When item is not attached to a collection
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws SourceChangeSubscriberException Whatever a subscriber to the collection's announcement raises
     * @throws HilosException Whatever preparing the row's immutable operation raises
     * @throws InvalidArgumentException When a retirement allowance of the hand-off is invalid
     */
    protected function removeAsCleanup(): void
    {
        $this->ensureCanWrite(TruthSourceOperation::Remove);
        RtOrdinaryAdmission::removeAsCleanup($this->getRtCollection()->getStateCollection(), $this->getRtCollectionKey(), $this->state);
    }

    /**
     * Apply diff to backing state, then sync it.
     *
     * This is the door every write of an action takes: its values are known before the row
     * changes. The right is judged, the whole diff is taken on the row's draft - a refused field
     * publishes no field - and the right is judged again for the set the draft stands in, so a
     * move to another set is refused before it is made. The update is then prepared as one
     * immutable operation and admitted on the source's ordinary FIFO, and only then is the draft
     * published in one swap and synced: a refusal of either kind reaches the caller while no
     * field has changed. A diff that changes nothing reserves, publishes and syncs nothing - not
     * even the hooks a concrete action hangs on its sync().
     *
     * A segmented diff is taken by the row's own resumable patch, in portions: a long list it
     * carries ({@see RtState::appendedListDiff()}) is neither materialized nor spread. A
     * native diff carries the action's own bounded values and is read by the row's applyDiff().
     * A legacy-only row takes a native diff alone and keeps writing field by field, admitted the
     * same way.
     *
     * @param array<string, mixed>|RtRowValues $diff Changed fields and their values
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source of the row or of its edited set
     * @throws RtWriteBackpressureException When the source's ordinary FIFO has no room; nothing was written
     * @throws HilosException Whatever the concrete state's read of the diff raises, a segmented diff of a legacy-only row included
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    protected function applyDiffWithSync(array|RtRowValues $diff): void
    {
        if (is_array($diff) ? $diff === [] : $diff->isEmptyMap()) {
            return;
        }
        $this->ensureCanWrite();
        $state = $this->state;
        if (!$state->hasRowBinding()) {
            if (!is_array($diff)) {
                throw new RtRowCodecNotSupportedException('A segmented diff needs a row with a binding: ' . $state::class);
            }
            $this->applyDiffToLegacyState($diff);

            return;
        }
        $draft = $state->draftRow();
        if (is_array($diff)) {
            $draft->applyDiff($diff);
        } else {
            RtRowValues::completed($state->rowCodec()->patch($draft, $diff->reader()));
        }
        $collectionKey = $this->getRtCollectionKey();
        RtTruthSourceRegistry::checkCanWriteState($collectionKey, $state->getId(), $draft->touchedSetKeys(), TruthSourceOperation::Update);
        $mutation = RtOrdinaryAdmission::operation(TableMutationType::Update, $collectionKey, $state, $draft);
        if ($mutation instanceof RtMutation && $mutation->values()->isEmptyMap()) {
            return;
        }
        $admission = RtOrdinaryAdmission::reserve(1);
        $admission->seal([$mutation]);
        try {
            $state->publishDraft($draft);
            $this->sync();
        } finally {
            $admission->settle();
        }
        RtOrdinaryAdmission::supersedeOwed($collectionKey, $state->getId());
    }

    /**
     * Apply a terminal transition of this row - the values an ended run, a lifted freeze or a
     * stopped owner leaves it with - as mandatory cleanup: through {@see self::applyDiffWithSync()},
     * on the bounded service reserve of the source's FIFO rather than its ordinary room, and never
     * refused for want of it. A transition the reserve cannot take now is owed by this row's
     * identity and retried through the same door as the FIFO frees; the last one owed wins, and a
     * later ordinary write of the row supersedes it ({@see RtOrdinaryAdmission::updateAsCleanup()}).
     *
     * @param array<string, mixed> $diff The row's terminal values
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source of the row or of its edited set
     * @throws HilosException Whatever the concrete state's read of the diff raises
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    protected function applyDiffAsCleanup(array $diff): void
    {
        $this->ensureCanWrite();
        RtOrdinaryAdmission::updateAsCleanup($this->getRtCollectionKey(), $this->state, function () use ($diff): void {
            $this->applyDiffWithSync($diff);
        });
    }

    /**
     * The field-by-field write of a legacy-only row, admitted on the source's ordinary FIFO with its
     * native diff before the first field is written.
     *
     * @param array<string, mixed> $diff Changed fields and values
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws RtWriteBackpressureException When the source's ordinary FIFO has no room; nothing was written
     * @throws HilosException Whatever the concrete state's read of the diff raises
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    private function applyDiffToLegacyState(array $diff): void
    {
        $admission = RtOrdinaryAdmission::reserve(1);
        $admission->seal([$diff]);
        try {
            $this->state->applyDiff($diff);
            $this->sync();
        } finally {
            $admission->settle();
        }
        RtOrdinaryAdmission::supersedeOwed($this->getRtCollectionKey(), $this->state->getId());
    }
}
