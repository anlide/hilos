<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Item;

use Hilos\Runtime\Exception\Item\RtItemActionsClassException;
use Hilos\Runtime\Exception\Item\RtItemPropertyNotFoundException;
use Hilos\Runtime\State\Item\BackupRuntime as StateBackupRuntime;
use Hilos\Runtime\View\Actions\Item\BackupRuntimeActions;

/**
 * Read-only view of the backup subsystem's runtime singleton.
 *
 * The row says whether a backup is running right now and which one; it is what the
 * backup page, the history table and the agent itself ask before admitting or listing
 * a run. Writing goes through {@see BackupRuntimeActions}, because only the write path
 * puts the change on the RT sync wire and every other worker keeps an idle row until it
 * arrives.
 *
 * @extends RtItem<StateBackupRuntime>
 *
 * @property-read bool $running Whether a backup is currently running
 * @property-read ?string $currentBackupId Id of the backup in progress; null when idle
 * @property-read ?string $scope Scope value of the backup in progress; null when idle
 * @property-read ?string $startedAt ISO-8601 start time of the backup in progress; null when idle
 * @property-read BackupRuntimeActions $actions Write operations for the runtime singleton
 */
final class BackupRuntime extends RtItem
{
    /**
     * @param StateBackupRuntime $state Backing runtime state
     */
    public function __construct(StateBackupRuntime &$state)
    {
        parent::__construct($state);
    }

    /**
     * @param string $name Property name
     * @return string|bool|BackupRuntimeActions|null Property value
     * @throws RtItemActionsClassException When item actions class is missing or invalid
     * @throws RtItemPropertyNotFoundException When $name is not a declared property
     */
    public function __get(string $name): string|bool|BackupRuntimeActions|null
    {
        return match ($name) {
            StateBackupRuntime::running => $this->_state->running,
            StateBackupRuntime::currentBackupId => $this->_state->currentBackupId,
            StateBackupRuntime::scope => $this->_state->scope,
            StateBackupRuntime::startedAt => $this->_state->startedAt,
            RtItem::actions => $this->getItemActions(),
            default => parent::__get($name),
        };
    }

    /**
     * Reports whether the given id names the backup running right now.
     *
     * Both halves of the question are asked together on purpose: an id left over from a
     * finished run must not read as running, and a run in progress must not answer for a
     * neighbouring id.
     *
     * @param string $backupId Backup id to test
     * @return bool Whether that backup is the one in progress
     */
    public function isRunning(string $backupId): bool
    {
        return $this->_state->running && $this->_state->currentBackupId === $backupId;
    }

    /**
     * @return array<string, mixed> Full state row
     */
    public function toArray(): array
    {
        return $this->_state->toArray();
    }
}
