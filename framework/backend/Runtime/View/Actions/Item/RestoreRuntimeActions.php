<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Actions\Item;

use DateTimeImmutable;
use DateTimeInterface;
use Hilos\Backup\BackupScope;
use Hilos\Backup\BackupStatus;
use Hilos\Backup\RestorePhase;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Item\RestoreRuntime as StateRestoreRuntime;
use Hilos\Runtime\View\Item\RestoreRuntime as ViewRestoreRuntime;

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
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function markRunning(string $backupId, BackupScope $scope): void
    {
        $this->ensureCanWrite();

        $this->state->running = true;
        $this->state->backupId = $backupId;
        $this->state->scope = $scope->value;
        $this->state->phase = RestorePhase::PENDING->value;
        $this->state->startedAt = new DateTimeImmutable()->format(DateTimeInterface::ATOM);
        $this->state->finishedAt = null;
        $this->state->outcome = null;
        $this->state->failureReason = null;
        $this->state->rehydrateComplete = false;
        $this->state->rehydrateProblems = [];
        $this->state->databaseTouched = false;
        $this->sync();
    }

    /**
     * Advances the run to the given phase and syncs the change to readers.
     *
     * Terminal phases do not pass through here: {@see self::finish()} derives them from
     * the outcome, so a run cannot read as failed in one field and running in another.
     *
     * @param RestorePhase $phase Non-terminal phase the run is entering
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function markPhase(RestorePhase $phase): void
    {
        $this->ensureCanWrite();

        $this->state->phase = $phase->value;
        $this->sync();
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
     */
    public function markRehydrating(): void
    {
        $this->ensureCanWrite();

        $this->state->phase = RestorePhase::REHYDRATING->value;
        $this->state->rehydrateComplete = false;
        $this->state->rehydrateProblems = [];
        $this->sync();
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
     */
    public function markRehydrateOutcome(bool $complete, array $problems): void
    {
        $this->ensureCanWrite();

        $this->state->rehydrateComplete = $complete;
        $this->state->rehydrateProblems = $problems;
        $this->sync();
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
     */
    public function markDatabaseTouched(bool $touched): void
    {
        $this->ensureCanWrite();

        $this->state->databaseTouched = $touched;
        $this->sync();
    }

    /**
     * Records the run's terminal outcome and syncs the change to readers.
     *
     * The running flag drops and the phase is derived from the outcome, but the id,
     * scope and times stay: a monitor polling just after the finish still needs to see
     * how the run it watched ended. {@see self::clear()} is the eventual reset.
     *
     * @param BackupStatus $outcome How the run ended
     * @param ?string $failureReason Operator-facing failure detail; null on success
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function finish(BackupStatus $outcome, ?string $failureReason = null): void
    {
        $this->ensureCanWrite();

        $this->state->running = false;
        $this->state->phase = $outcome === BackupStatus::SUCCESS
            ? RestorePhase::SUCCEEDED->value
            : RestorePhase::FAILED->value;
        $this->state->finishedAt = new DateTimeImmutable()->format(DateTimeInterface::ATOM);
        $this->state->outcome = $outcome->value;
        $this->state->failureReason = $failureReason;
        $this->sync();
    }

    /**
     * Returns the singleton to the idle row and syncs the change to readers.
     *
     * Every field is cleared, not only the flag: a leftover id, phase or outcome would
     * keep readers describing a run that is over and already reported.
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function clear(): void
    {
        $this->ensureCanWrite();

        $this->state->running = false;
        $this->state->backupId = null;
        $this->state->scope = null;
        $this->state->phase = null;
        $this->state->startedAt = null;
        $this->state->finishedAt = null;
        $this->state->outcome = null;
        $this->state->failureReason = null;
        $this->state->rehydrateComplete = false;
        $this->state->rehydrateProblems = [];
        $this->state->databaseTouched = false;
        $this->sync();
    }
}
