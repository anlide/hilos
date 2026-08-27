<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Item;

use Hilos\Runtime\Exception\Item\RtItemActionsClassException;
use Hilos\Runtime\Exception\Item\RtItemPropertyNotFoundException;
use Hilos\Runtime\State\Item\RestoreRuntime as StateRestoreRuntime;
use Hilos\Runtime\View\Actions\Item\RestoreRuntimeActions;

/**
 * Read-only view of the restore runtime singleton.
 *
 * The row says whether a restore is running right now, which backup it replays, how
 * far it got and how the last run ended; the agent's status reply is built from it.
 * Writing goes through {@see RestoreRuntimeActions}, because only the write path puts
 * the change on the RT sync wire and every other worker keeps an idle row until it
 * arrives.
 *
 * @extends RtItem<StateRestoreRuntime>
 *
 * @property-read bool $running Whether a restore is currently running
 * @property-read ?string $backupId Id of the backup being restored; null when idle
 * @property-read ?string $scope Scope value of the backup being restored; null when idle
 * @property-read ?string $phase Current restore phase value; null when idle
 * @property-read ?string $phaseStartedAt ISO-8601 instant the current phase began; null when there is no phase
 * @property-read ?string $startedAt ISO-8601 start time of the restore in progress; null when idle
 * @property-read ?string $finishedAt ISO-8601 finish time of the last restore; null while running or idle
 * @property-read ?string $outcome Terminal outcome value of the last restore; null until one finishes
 * @property-read ?string $failureReason Why the last restore failed; null when it succeeded or never ran
 * @property-read ?int $estimatedSeconds Expected duration of the run; null when no restore of the scope was recorded
 * @property-read bool $rehydrateComplete Whether every process confirmed re-reading the replaced database
 * @property-read list<string> $rehydrateProblems Processes that failed to re-read or never answered
 * @property-read bool $databaseTouched Whether the run reached its first destructive step
 * @property-read RestoreRuntimeActions $actions Write operations for the runtime singleton
 */
final class RestoreRuntime extends RtItem
{
    /**
     * @param StateRestoreRuntime $state Backing runtime state
     */
    public function __construct(StateRestoreRuntime $state)
    {
        parent::__construct($state);
    }

    /**
     * @param string $name Property name
     * @return string|int|bool|array<int, string>|RestoreRuntimeActions|null Property value
     * @throws RtItemActionsClassException When item actions class is missing or invalid
     * @throws RtItemPropertyNotFoundException When $name is not a declared property
     */
    public function __get(string $name): string|int|bool|array|RestoreRuntimeActions|null
    {
        return match ($name) {
            StateRestoreRuntime::running => $this->_state->running,
            StateRestoreRuntime::backupId => $this->_state->backupId,
            StateRestoreRuntime::scope => $this->_state->scope,
            StateRestoreRuntime::phase => $this->_state->phase,
            StateRestoreRuntime::phaseStartedAt => $this->_state->phaseStartedAt,
            StateRestoreRuntime::startedAt => $this->_state->startedAt,
            StateRestoreRuntime::finishedAt => $this->_state->finishedAt,
            StateRestoreRuntime::outcome => $this->_state->outcome,
            StateRestoreRuntime::failureReason => $this->_state->failureReason,
            StateRestoreRuntime::estimatedSeconds => $this->_state->estimatedSeconds,
            StateRestoreRuntime::rehydrateComplete => $this->_state->rehydrateComplete,
            StateRestoreRuntime::rehydrateProblems => $this->_state->rehydrateProblems,
            StateRestoreRuntime::databaseTouched => $this->_state->databaseTouched,
            RtItem::actions => $this->getItemActions(),
            default => parent::__get($name),
        };
    }

    /**
     * @return array<string, mixed> Full state row
     */
    public function toArray(): array
    {
        return $this->_state->toArray();
    }
}
