<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Actions\Item;

use Hilos\Backup\BackupMetadata;
use Hilos\Core\Exception\InvalidArgumentException;
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
     * Drops the row from the index and broadcasts the removal.
     *
     * The stored files are deleted by the backup engine; this is the index half, called
     * after a manual delete or a rotation pass.
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtItemParentCollectionNullException When item is not attached to a collection
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    public function delete(): void
    {
        $this->remove();
    }
}
