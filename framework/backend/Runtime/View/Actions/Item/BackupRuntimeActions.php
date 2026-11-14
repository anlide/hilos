<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Actions\Item;

use DateTimeImmutable;
use Hilos\Backup\BackupPhase;
use Hilos\Backup\BackupProgress;
use Hilos\Backup\BackupScope;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Item\BackupRuntime as StateBackupRuntime;
use Hilos\Runtime\View\Item\BackupRuntime as ViewBackupRuntime;
use Hilos\Utils\Helpers\UtcMoment;
use Hilos\HilosException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Runtime\Exception\RtWriteBackpressureException;

/**
 * Write operations for the backup runtime singleton.
 *
 * The methods are the whole lifecycle of the row: a run starts, moves through its phases, and
 * ends. They are semantic rather than field-wise because the fields only make sense together — an
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
     * @param ?int $estimatedSeconds How long the run is expected to take; null when there is no history
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws RtWriteBackpressureException When the source's ordinary RT FIFO has no room; nothing was written
     * @throws HilosException Whatever the row's read of the diff or the preparation of its RT operation raises
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    public function markRunning(string $backupId, BackupScope $scope, ?int $estimatedSeconds = null): void
    {
        $this->ensureCanWrite();

        $this->applyDiffWithSync([
            StateBackupRuntime::running => true,
            StateBackupRuntime::currentBackupId => $backupId,
            StateBackupRuntime::scope => $scope->value,
            StateBackupRuntime::startedAt => UtcMoment::format(new DateTimeImmutable()),
            StateBackupRuntime::phase => null,
            StateBackupRuntime::phaseStartedAt => null,
            StateBackupRuntime::estimatedSeconds => $estimatedSeconds,
            StateBackupRuntime::percent => null,
            StateBackupRuntime::remainingSeconds => null,
        ]);
    }

    /**
     * Records that the run has entered a phase and syncs the change to readers.
     *
     * The instant is stamped here for the reason the start time is: it is the transition itself.
     * The two fields move together because a phase without the instant it began carries no
     * progress at all - the share of the run behind it would be all a reader could tell.
     *
     * @param BackupPhase $phase Phase the run has just entered
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws RtWriteBackpressureException When the source's ordinary RT FIFO has no room; nothing was written
     * @throws HilosException Whatever the row's read of the diff or the preparation of its RT operation raises
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    public function markPhase(BackupPhase $phase): void
    {
        $this->ensureCanWrite();

        $this->applyDiffWithSync([
            StateBackupRuntime::phase => $phase->value,
            StateBackupRuntime::phaseStartedAt => UtcMoment::format(new DateTimeImmutable()),
        ]);
    }

    /**
     * Puts the figures a progress bar is drawn from on the row and syncs them to readers.
     *
     * The arithmetic ({@see BackupProgress}) is done by the agent rather than by each surface that
     * shows the run: the phase weights make a run sit in one phase for most of its wall-clock, so
     * a row written only on a phase change would leave every bar frozen between them. The two
     * figures move together because a share without the time left is half an answer.
     *
     * @param ?int $percent How far along the run is, 0..99; null when it cannot be estimated
     * @param ?int $remainingSeconds Seconds left, negative once the estimate is spent; null when it cannot be estimated
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws RtWriteBackpressureException When the source's ordinary RT FIFO has no room; nothing was written
     * @throws HilosException Whatever the row's read of the diff or the preparation of its RT operation raises
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    public function markProgress(?int $percent, ?int $remainingSeconds): void
    {
        $this->ensureCanWrite();

        $this->applyDiffWithSync([
            StateBackupRuntime::percent => $percent,
            StateBackupRuntime::remainingSeconds => $remainingSeconds,
        ]);
    }

    /**
     * Returns the singleton to idle and syncs the change to readers.
     *
     * Every field is cleared, not only the flag: a leftover id or scope would keep the
     * page and the table describing a run that is over, and a leftover phase would leave a
     * progress bar frozen wherever the run happened to end instead of taking it away.
     *
     * A terminal transition: it takes the service reserve of the source's FIFO and is never refused
     * for want of room - one the reserve cannot take now is owed and retried as the FIFO frees.
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws HilosException Whatever the row's read of the diff or the preparation of its RT operation raises
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    public function clearRunning(): void
    {
        $this->ensureCanWrite();

        $this->applyDiffAsCleanup([
            StateBackupRuntime::running => false,
            StateBackupRuntime::currentBackupId => null,
            StateBackupRuntime::scope => null,
            StateBackupRuntime::startedAt => null,
            StateBackupRuntime::phase => null,
            StateBackupRuntime::phaseStartedAt => null,
            StateBackupRuntime::estimatedSeconds => null,
            StateBackupRuntime::percent => null,
            StateBackupRuntime::remainingSeconds => null,
        ]);
    }
}
