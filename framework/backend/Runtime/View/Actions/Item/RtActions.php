<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Actions\Item;

use Hilos\Constants\SignalConstants;
use Hilos\Core\Sync\DTO\RtSyncDeletedSignalData;
use Hilos\Hilos;
use Hilos\Database\Actions\Item\DbActions;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Item\RtItemParentCollectionNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Collection\RtCollection;
use Hilos\Runtime\View\Item\RtItem;
use Hilos\TruthSource\RtTruthSourceRegistry;

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
     * @throws \InvalidArgumentException If property unknown
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            self::state => $this->item->getState(),
            default => throw new \InvalidArgumentException("Unknown property: {$name}"),
        };
    }

    /**
     * Parent collection (truth source name, sync, view cache).
     *
     * @throws RtItemParentCollectionNullException If item was not wired via RtCollection
     */
    protected function getRtCollection(): RtCollection
    {
        return $this->item->getCollection()
            ?? throw new RtItemParentCollectionNullException(
                'RtItem has no parent collection; item actions require RtCollection::attachItemToCollection().'
            );
    }

    /**
     * @throws RtActionsCollectionNameNullException
     */
    protected function ensureCanWrite(): void
    {
        $collectionName = $this->getRtCollection()->getCollectionName()
            ?? throw new RtActionsCollectionNameNullException(
                'Cannot ensure write: collection name is null'
            );
        RtTruthSourceRegistry::checkCanWriteState($collectionName, $this->state->getId());
    }

    /**
     * Queue RT_SYNC_UPDATED for pending state changes and clear the view cache.
     * Call after mutating {@see $state} (assignments or applyDiff), analogous to Object_::sync() for workers.
     */
    protected function sync(): void
    {
        $this->state->sync();
        $this->getRtCollection()->clearCache();
    }

    /**
     * Delete the current runtime item and broadcast the previous row.
     *
     * @throws RtActionsCollectionNameNullException When collection name is null.
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable.
     * @throws RtItemParentCollectionNullException When item is not attached to a collection.
     * @throws RtTruthSourceWriteNotAllowedException When truth source does not allow write.
     */
    protected function remove(): void
    {
        $this->ensureCanWrite();

        $collection = $this->getRtCollection();
        $collectionName = $collection->getCollectionName()
            ?? throw new RtActionsCollectionNameNullException(
                'Cannot remove runtime item: collection name is null'
            );

        $stateId = $this->state->getId();
        $row = $this->state->toArray();
        $collection->getStateCollection()->remove($stateId);
        $collection->clearCache();

        Hilos::$sr?->queueRtSyncSignal(
            SignalConstants::RT_SYNC_DELETED,
            new RtSyncDeletedSignalData($collectionName, $stateId, $row),
        );
    }

    /**
     * Apply diff to backing state, then {@see sync()}.
     *
     * @param array<string, mixed> $diff
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
