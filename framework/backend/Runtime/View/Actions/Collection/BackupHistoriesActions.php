<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Actions\Collection;

use Hilos\Backup\BackupMetadata;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Runtime\Exception\Actions\RtActionsCallbackNotSetException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsItemClassException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Item\RtItemParentCollectionNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Collection\BackupHistories as StateBackupHistories;
use Hilos\Runtime\State\Item\BackupHistory as StateBackupHistory;
use Hilos\Runtime\View\Collection\BackupHistories;
use Hilos\Runtime\View\Item\BackupHistory as ViewBackupHistory;

/**
 * Write API for the stored-backup index.
 *
 * Storage is the truth and this collection is its projection, so the only write it
 * offers is "make the index match what the scan found" ({@see syncToScan()}). It is a
 * diff, not a rebuild: a rescan that found nothing new queues nothing, and one new
 * archive costs exactly one create signal — which is what a browser table needs to
 * grow a single row instead of being torn down and rebuilt.
 *
 * @extends RtActions<ViewBackupHistory, BackupHistories, StateBackupHistories>
 * @property-read StateBackupHistories $stateCollection
 */
final class BackupHistoriesActions extends RtActions
{
    /**
     * Brings the index in line with a storage scan.
     *
     * Rows absent from the scan are dropped, new ones are added, and surviving ones are
     * re-projected (a keep pin toggled on disk lands here). Every change goes out as its
     * own RT sync signal, so other workers and the browser tables follow along.
     *
     * @param list<BackupMetadata> $metadatas Sidecars found by the scan
     * @return int Number of rows created, removed, or changed
     * @throws RtActionsCallbackNotSetException When runtime item factory callback is not configured
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtItemParentCollectionNullException When a dropped row is not attached to the collection
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     * @throws RtActionsItemClassException When the item factory returns a class the collection does not accept
     */
    public function syncToScan(array $metadatas): int
    {
        $this->ensureCanWrite();

        $scanned = [];
        foreach ($metadatas as $metadata) {
            $scanned[$metadata->id] = $metadata;
        }

        $changes = 0;

        // Collect first: dropping a row mutates the collection the loop walks.
        $gone = [];
        foreach ($this->stateCollection as $state) {
            if (!isset($scanned[$state->getId()])) {
                $gone[] = $state->getId();
            }
        }
        foreach ($gone as $id) {
            if ($this->forget($id)) {
                $changes++;
            }
        }

        foreach ($scanned as $id => $metadata) {
            if ($this->applyScanned((string)$id, $metadata)) {
                $changes++;
            }
        }

        return $changes;
    }

    /**
     * Adds or re-projects one scanned backup, reporting whether the index moved.
     *
     * A method rather than a loop body on purpose: {@see createRtItemFromState()} binds the
     * view to the *variable* holding the state, so a variable reused across iterations would
     * be re-bound under an already-created item — and assigning the next row (or null) into
     * that reference breaks it.
     *
     * @param string $id Backup id
     * @param BackupMetadata $metadata Scanned sidecar metadata
     * @return bool True when a row was created or changed
     * @throws RtActionsCallbackNotSetException When runtime item factory callback is not configured
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    private function applyScanned(string $id, BackupMetadata $metadata): bool
    {
        $existing = $this->stateCollection->get($id);
        if ($existing === null) {
            $this->addStateToCollection(StateBackupHistory::fromMetadata($metadata));

            return true;
        }

        $before = $existing->toArray();
        $this->createRtItemFromState($existing)->actions->project($metadata);

        return $existing->toArray() !== $before;
    }

    /**
     * Drops one row from the index by id, if it is there.
     *
     * @param string $id Backup id
     * @return bool True when a row was removed
     * @throws RtActionsCallbackNotSetException When runtime item factory callback is not configured
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtItemParentCollectionNullException When the row is not attached to the collection
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws RtActionsItemClassException When the item factory returns a class the collection does not accept
     */
    public function forget(string $id): bool
    {
        $this->ensureCanWrite();

        $state = $this->stateCollection->get($id);
        if ($state === null) {
            return false;
        }

        $this->createRtItemFromState($state)->actions->delete();

        return true;
    }
}
