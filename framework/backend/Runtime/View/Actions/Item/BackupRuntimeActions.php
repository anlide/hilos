<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Actions\Item;

use DateTimeImmutable;
use DateTimeInterface;
use Hilos\Backup\BackupScope;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Item\BackupRuntime as StateBackupRuntime;
use Hilos\Runtime\View\Item\BackupRuntime as ViewBackupRuntime;

/**
 * Write operations for the backup runtime singleton.
 *
 * The two methods are the whole lifecycle of the row: a run starts and a run ends. They
 * are semantic rather than field-wise because the fields only make sense together — an
 * id without the running flag, or a start time left behind by a finished run, is a row
 * no reader can interpret. The monopoly backup agent is the only sanctioned caller;
 * {@see RtActions::ensureCanWrite()} enforces that.
 *
 * @extends RtActions<ViewBackupRuntime, StateBackupRuntime>
 * @property-read StateBackupRuntime $state
 */
final class BackupRuntimeActions extends RtActions
{
    /**
     * Marks the singleton as running the given backup and syncs the change to readers.
     *
     * The start time is stamped here rather than passed in: it is part of the transition,
     * and a caller-supplied instant would let two writers disagree about when the same run
     * began.
     *
     * @param string $backupId Backup id in progress
     * @param BackupScope $scope Scope of the backup in progress
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function markRunning(string $backupId, BackupScope $scope): void
    {
        $this->ensureCanWrite();

        $this->state->running = true;
        $this->state->currentBackupId = $backupId;
        $this->state->scope = $scope->value;
        $this->state->startedAt = new DateTimeImmutable()->format(DateTimeInterface::ATOM);
        $this->sync();
    }

    /**
     * Returns the singleton to idle and syncs the change to readers.
     *
     * Every field is cleared, not only the flag: a leftover id or scope would keep the
     * page and the table describing a run that is over.
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function clearRunning(): void
    {
        $this->ensureCanWrite();

        $this->state->running = false;
        $this->state->currentBackupId = null;
        $this->state->scope = null;
        $this->state->startedAt = null;
        $this->sync();
    }
}
