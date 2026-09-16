<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Actions\Collection;

use Hilos\Backup\BackupMetadata;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
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
 * offers is "make the index match what the scan on THIS node found" ({@see syncToScan()}).
 * Storage is a local directory, so a scan speaks for one node's disk and no other: a row
 * another node's scan put here is marked out of reach rather than dropped, because its
 * archive is still where it was - only the agent has moved. It is a diff, not a rebuild:
 * a rescan that found nothing new queues nothing, and one new archive costs exactly one
 * create signal — which is what a browser table needs to grow a single row instead of
 * being torn down and rebuilt.
 *
 * @extends RtActions<ViewBackupHistory, BackupHistories, StateBackupHistories>
 * @property-read StateBackupHistories $stateCollection
 */
final class BackupHistoriesActions extends RtActions
{
    /**
     * Brings the index in line with a storage scan.
     *
     * New rows are added and surviving ones re-projected (a keep pin toggled on disk lands
     * here), both stamped with the scanning node. A row absent from the scan is judged by the
     * node it names:
     *
     * - this node, or no node at all: the archive is gone from this disk, and the row is
     *   dropped. The null arm is what lets an installation that was single-node and later
     *   joined a cluster keep its history - its old rows name no node, they lie in this very
     *   directory, and the scan that finds them stamps them;
     * - another node: the archive is still on that node's disk, and the row is kept and marked
     *   out of reach, so "these archives are somewhere else" never reads as "no backups".
     *
     * Every change goes out as its own RT sync signal, so other workers and the browser tables
     * follow along.
     *
     * @param list<BackupMetadata> $metadatas Sidecars found by the scan
     * @param ?string $nodeId Node that ran the scan, or null on an installation without clustering
     * @return int Number of rows created, removed, marked, or changed
     * @throws RtActionsCallbackNotSetException When runtime item factory callback is not configured
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtItemParentCollectionNullException When a dropped row is not attached to the collection
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws SourceChangeSubscriberException Whatever a subscriber to the collection's announcement raises
     * @throws RtActionsItemClassException When the item factory returns a class the collection does not accept
     */
    public function syncToScan(array $metadatas, ?string $nodeId): int
    {
        $this->ensureCanWrite();

        $scanned = [];
        foreach ($metadatas as $metadata) {
            $scanned[$metadata->id] = $metadata;
        }

        $changes = 0;

        foreach ($this->stateCollection as $state) {
            if (isset($scanned[$state->getId()])) {
                continue;
            }
            if ($state->nodeId === null || $state->nodeId === $nodeId) {
                if ($this->forget($state->getId())) {
                    $changes++;
                }

                continue;
            }
            if ($this->createRtItemFromState($state)->actions->markOutOfReach()) {
                $changes++;
            }
        }

        foreach ($scanned as $id => $metadata) {
            if ($this->applyScanned((string)$id, $metadata, $nodeId)) {
                $changes++;
            }
        }

        return $changes;
    }

    /**
     * Adds or re-projects one scanned backup, reporting whether the index moved.
     *
     * @param string $id Backup id
     * @param BackupMetadata $metadata Scanned sidecar metadata
     * @param ?string $nodeId Node that ran the scan, or null on an installation without clustering
     * @return bool True when a row was created or changed
     * @throws RtActionsCallbackNotSetException When runtime item factory callback is not configured
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    private function applyScanned(string $id, BackupMetadata $metadata, ?string $nodeId): bool
    {
        $existing = $this->stateCollection->get($id);
        if ($existing === null) {
            $this->addStateToCollection(StateBackupHistory::fromMetadata($metadata, $nodeId));

            return true;
        }

        $before = $existing->toArray();
        $this->createRtItemFromState($existing)->actions->project($metadata, $nodeId);

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
