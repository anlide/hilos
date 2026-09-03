<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Actions\Item;

use Hilos\Backup\BackupCreator;
use Hilos\Backup\BackupMetadata;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Item\RtItemParentCollectionNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Item\BackupHistory as StateBackupHistory;
use Hilos\Runtime\View\Item\BackupHistory as ViewBackupHistory;

/**
 * Write operations for one stored-backup index row.
 *
 * Both go through the base class rather than touching the state collection, so each
 * change is broadcast as an RT sync signal: without that the row would only ever
 * change inside the backup agent's own worker, and every other worker — including the
 * one serving the backup page — would keep an index that never moves.
 *
 * @extends RtActions<ViewBackupHistory, StateBackupHistory>
 * @property-read StateBackupHistory $state
 */
final class BackupHistoryActions extends RtActions
{
    /**
     * Re-projects the row from a rescanned sidecar, syncing whatever changed.
     *
     * Used by the index refresh for a backup that is still stored but whose sidecar
     * moved (a keep pin toggled, an error record rewritten). A row that did not change
     * queues nothing.
     *
     * @param BackupMetadata $metadata Freshly scanned sidecar metadata
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function project(BackupMetadata $metadata): void
    {
        $this->ensureCanWrite();

        $fresh = StateBackupHistory::fromMetadata($metadata)->toArray();
        $current = $this->state->toArray();

        $diff = [];
        foreach ($fresh as $field => $value) {
            if (($current[$field] ?? null) !== $value) {
                $diff[$field] = $value;
            }
        }

        $this->applyDiffWithSync($diff);
    }

    /**
     * Records that this archive has just been restored, syncing the two fields to readers.
     *
     * The index half of what {@see BackupCreator::recordRestore()} writes into the sidecar: files
     * are the truth, and this keeps the readers from having to wait for the next scan to learn a
     * restore happened. A restore is the only history restores have, which is why the archive
     * carries it - the estimate for the next one is read back out of these rows.
     *
     * @param string $restoredAt ISO-8601 instant the restore finished
     * @param int $durationSeconds How long that restore took, in seconds
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function recordRestore(string $restoredAt, int $durationSeconds): void
    {
        $this->ensureCanWrite();

        $this->applyDiffWithSync([
            StateBackupHistory::restoredAt => $restoredAt,
            StateBackupHistory::restoreDurationSeconds => $durationSeconds,
        ]);
    }

    /**
     * Records the outcome of a copy off this machine, syncing the four fields to readers.
     *
     * The index half of what {@see BackupCreator::recordShipping()} writes into the sidecar: files
     * are the truth, and this keeps the Copy column of every other worker's table from waiting for
     * the next scan to learn that a transfer finished or failed.
     *
     * @param ?string $shippedAt ISO-8601 instant of the last successful copy; null means never
     * @param ?string $shipOutcome Outcome value of the attempt; null when never attempted
     * @param ?string $shipError Why the attempt failed; null on success
     * @param ?string $shipEncryption Fingerprint of the recipient set the copy was encrypted to;
     *     null when it left in the clear
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function recordShipping(
        ?string $shippedAt,
        ?string $shipOutcome,
        ?string $shipError,
        ?string $shipEncryption,
    ): void {
        $this->ensureCanWrite();

        $this->applyDiffWithSync([
            StateBackupHistory::shippedAt => $shippedAt,
            StateBackupHistory::shipOutcome => $shipOutcome,
            StateBackupHistory::shipError => $shipError,
            StateBackupHistory::shipEncryption => $shipEncryption,
        ]);
    }

    /**
     * Drops the row from the index and broadcasts the removal.
     *
     * The stored files are deleted by the backup engine; this is the index half, called
     * after a manual delete or a rotation pass.
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtItemParentCollectionNullException When item is not attached to a collection
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws SourceChangeSubscriberException Whatever a subscriber to the collection's announcement raises
     */
    public function delete(): void
    {
        $this->remove();
    }
}
