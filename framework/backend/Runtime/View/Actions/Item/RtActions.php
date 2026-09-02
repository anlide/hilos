<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Actions\Item;

use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Actions\Item\DbActions;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Item\RtItemParentCollectionNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Collection\RtCollection;
use Hilos\Runtime\View\Item\RtItem;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Core\Exception\InvalidArgumentException;

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
     * @param TruthSourceOperation $operation Operation the caller is about to perform
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When the row or the operation is not the caller's
     */
    protected function ensureCanWrite(TruthSourceOperation $operation = TruthSourceOperation::Update): void
    {
        RtTruthSourceRegistry::checkCanWriteState($this->getRtCollectionKey(), $this->state->getId(), $operation);
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
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtItemParentCollectionNullException When item is not attached to a collection
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws SourceChangeSubscriberException Whatever a subscriber to the collection's announcement raises
     */
    protected function remove(): void
    {
        $this->ensureCanWrite(TruthSourceOperation::Remove);

        $this->getRtCollection()->getStateCollection()->remove($this->state->getId());
    }

    /**
     * Apply diff to backing state, then sync it.
     *
     * @param array<string, mixed> $diff
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    protected function applyDiffWithSync(array $diff): void
    {
        if ($diff === []) {
            return;
        }
        $this->ensureCanWrite();
        $this->state->applyDiff($diff);
        $this->sync();
    }
}
