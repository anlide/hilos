<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Actions\Item;

use DateTimeImmutable;
use Hilos\Backup\BackupScope;
use Hilos\Backup\BackupStatus;
use Hilos\Backup\RestorePhase;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Item\RestoreRuntime as StateRestoreRuntime;
use Hilos\Runtime\View\Item\RestoreRuntime as ViewRestoreRuntime;
use Hilos\Utils\Helpers\UtcMoment;
use Hilos\HilosException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Runtime\Exception\RtWriteBackpressureException;

/**
 * Write operations for the restore runtime singleton.
 *
 * The methods are the lifecycle of one restore run: it is admitted, it advances, it
 * ends, and its terminal record is eventually cleared. They are semantic rather than
 * field-wise because the fields only make sense together - a phase without the running
 * flag, or an outcome next to a live start time, is a row no reader can interpret. The
 * monopoly backup agent is the only sanctioned caller;
 * {@see RtActions::ensureCanWrite()} enforces that.
 *
 * @extends RtActions<ViewRestoreRuntime, StateRestoreRuntime>
 * @property-read StateRestoreRuntime $state
 */
final class RestoreRuntimeActions extends RtActions
{
    /**
     * Marks the singleton as running the given restore and syncs the change to readers.
     *
     * Starts in {@see RestorePhase::PENDING}: the run is admitted before protected mode
     * is up, and the phase advances via {@see self::markPhase()} once work begins. The
     * start time is stamped here rather than passed in, and the previous run's terminal
     * record is wiped - one row describes one run at a time.
     *
     * @param string $backupId Backup id being restored
     * @param BackupScope $scope Scope of the backup being restored
     * @param ?int $estimatedSeconds How long the run is expected to take; null when no restore of the
     *     scope has been recorded yet
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws RtWriteBackpressureException When the source's ordinary RT FIFO has no room; nothing was written
     * @throws HilosException Whatever the row's read of the diff or the preparation of its RT operation raises
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    public function markRunning(string $backupId, BackupScope $scope, ?int $estimatedSeconds = null): void
    {
        $this->ensureCanWrite();

        $startedAt = UtcMoment::format(new DateTimeImmutable());
        $this->applyDiffWithSync([
            StateRestoreRuntime::running => true,
            StateRestoreRuntime::backupId => $backupId,
            StateRestoreRuntime::scope => $scope->value,
            StateRestoreRuntime::phase => RestorePhase::PENDING->value,
            StateRestoreRuntime::phaseStartedAt => $startedAt,
            StateRestoreRuntime::startedAt => $startedAt,
            StateRestoreRuntime::estimatedSeconds => $estimatedSeconds,
            StateRestoreRuntime::finishedAt => null,
            StateRestoreRuntime::outcome => null,
            StateRestoreRuntime::failureReason => null,
            StateRestoreRuntime::rehydrateComplete => false,
            StateRestoreRuntime::rehydrateProblems => [],
            StateRestoreRuntime::databaseTouched => false,
        ]);
    }

    /**
     * Advances the run to the given phase and syncs the change to readers.
     *
     * Terminal phases do not pass through here: {@see self::finish()} derives them from
     * the outcome, so a run cannot read as failed in one field and running in another.
     *
     * The instant is stamped beside the phase, for the reason the start time is: a phase without
     * the moment it began says which share of the run is behind it and nothing about the share in
     * flight, so a progress bar would move once per phase and stand still in between.
     *
     * @param RestorePhase $phase Non-terminal phase the run is entering
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws RtWriteBackpressureException When the source's ordinary RT FIFO has no room; nothing was written
     * @throws HilosException Whatever the row's read of the diff or the preparation of its RT operation raises
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    public function markPhase(RestorePhase $phase): void
    {
        $this->ensureCanWrite();

        $this->applyDiffWithSync([
            StateRestoreRuntime::phase => $phase->value,
            StateRestoreRuntime::phaseStartedAt => UtcMoment::format(new DateTimeImmutable()),
        ]);
    }

    /**
     * Enters the post-import re-hydration and syncs the change to readers.
     *
     * A phase of its own rather than a flag on the run: between the child's exit and the terminal
     * outcome the node is putting itself back together, and an operator watching a monitor that
     * jumped straight from "importing" to "succeeded" would be told the restore was over while the
     * system was still closed. Set by the agent, because the engine is already gone (HIL-436).
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws RtWriteBackpressureException When the source's ordinary RT FIFO has no room; nothing was written
     * @throws HilosException Whatever the row's read of the diff or the preparation of its RT operation raises
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    public function markRehydrating(): void
    {
        $this->ensureCanWrite();

        $this->applyDiffWithSync([
            StateRestoreRuntime::phase => RestorePhase::REHYDRATING->value,
            StateRestoreRuntime::phaseStartedAt => UtcMoment::format(new DateTimeImmutable()),
            StateRestoreRuntime::rehydrateComplete => false,
            StateRestoreRuntime::rehydrateProblems => [],
        ]);
    }

    /**
     * Records how the re-hydration barrier ended and syncs the change to readers.
     *
     * The two travel together on purpose: a verdict with no names is an operator staring at a node
     * that stayed closed with nothing to look at, and names with no verdict are a list nobody knows
     * how to read.
     *
     * @param bool $complete Whether every process confirmed re-reading the replaced database
     * @param list<string> $problems Processes that failed to re-read or never answered, one line each
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws RtWriteBackpressureException When the source's ordinary RT FIFO has no room; nothing was written
     * @throws HilosException Whatever the row's read of the diff or the preparation of its RT operation raises
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    public function markRehydrateOutcome(bool $complete, array $problems): void
    {
        $this->ensureCanWrite();

        $this->applyDiffWithSync([
            StateRestoreRuntime::rehydrateComplete => $complete,
            StateRestoreRuntime::rehydrateProblems => $problems,
        ]);
    }

    /**
     * Records whether the run reached its first destructive step and syncs the change to readers.
     *
     * Written before the outcome, from the child's exit code: it decides which of two very
     * different sentences the monitor prints to whoever has to act on a failed restore.
     *
     * @param bool $touched Whether the run got as far as writing to the database
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws RtWriteBackpressureException When the source's ordinary RT FIFO has no room; nothing was written
     * @throws HilosException Whatever the row's read of the diff or the preparation of its RT operation raises
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    public function markDatabaseTouched(bool $touched): void
    {
        $this->ensureCanWrite();

        $this->applyDiffWithSync([
            StateRestoreRuntime::databaseTouched => $touched,
        ]);
    }

    /**
     * Records the run's terminal outcome and syncs the change to readers.
     *
     * The running flag drops and the phase is derived from the outcome, but the id,
     * scope and times stay: a monitor polling just after the finish still needs to see
     * how the run it watched ended. {@see self::clear()} is the eventual reset.
     *
     * The progress anchors go out here rather than staying with the times: the run is over, and a
     * bar that kept filling against an estimate nobody is running against anymore would be the one
     * thing on the screen still claiming work is happening.
     *
     * A terminal transition: it takes the service reserve of the source's FIFO and is never refused
     * for want of room - one the reserve cannot take now is owed and retried as the FIFO frees.
     *
     * @param BackupStatus $outcome How the run ended
     * @param ?string $failureReason Operator-facing failure detail; null on success
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws HilosException Whatever the row's read of the diff or the preparation of its RT operation raises
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    public function finish(BackupStatus $outcome, ?string $failureReason = null): void
    {
        $this->ensureCanWrite();

        $this->applyDiffAsCleanup([
            StateRestoreRuntime::running => false,
            StateRestoreRuntime::phase => $outcome === BackupStatus::SUCCESS
                ? RestorePhase::SUCCEEDED->value
                : RestorePhase::FAILED->value,
            StateRestoreRuntime::phaseStartedAt => null,
            StateRestoreRuntime::estimatedSeconds => null,
            StateRestoreRuntime::finishedAt => UtcMoment::format(new DateTimeImmutable()),
            StateRestoreRuntime::outcome => $outcome->value,
            StateRestoreRuntime::failureReason => $failureReason,
        ]);
    }

    /**
     * Returns the singleton to the idle row and syncs the change to readers.
     *
     * Every field is cleared, not only the flag: a leftover id, phase or outcome would
     * keep readers describing a run that is over and already reported.
     *
     * A terminal transition: it takes the service reserve of the source's FIFO and is never refused
     * for want of room - one the reserve cannot take now is owed and retried as the FIFO frees.
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws HilosException Whatever the row's read of the diff or the preparation of its RT operation raises
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    public function clear(): void
    {
        $this->ensureCanWrite();

        $this->applyDiffAsCleanup([
            StateRestoreRuntime::running => false,
            StateRestoreRuntime::backupId => null,
            StateRestoreRuntime::scope => null,
            StateRestoreRuntime::phase => null,
            StateRestoreRuntime::phaseStartedAt => null,
            StateRestoreRuntime::startedAt => null,
            StateRestoreRuntime::estimatedSeconds => null,
            StateRestoreRuntime::finishedAt => null,
            StateRestoreRuntime::outcome => null,
            StateRestoreRuntime::failureReason => null,
            StateRestoreRuntime::rehydrateComplete => false,
            StateRestoreRuntime::rehydrateProblems => [],
            StateRestoreRuntime::databaseTouched => false,
        ]);
    }
}
