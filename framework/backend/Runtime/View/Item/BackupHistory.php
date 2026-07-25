<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Item;

use Hilos\Backup\BackupConnectionMeta;
use Hilos\Runtime\Exception\Item\RtItemActionsClassException;
use Hilos\Runtime\Exception\Item\RtItemPropertyNotFoundException;
use Hilos\Runtime\State\Item\BackupHistory as StateBackupHistory;
use Hilos\Runtime\View\Actions\Item\BackupHistoryActions;

/**
 * Read-only wrapper over one stored-backup index row.
 *
 * The row is a projection of a sidecar on disk (files = truth); this view is what a
 * reader outside the backup agent gets, and its {@see BackupHistoryActions} is the
 * only sanctioned way to change or drop it, because that is what puts the change on
 * the RT sync wire for the other workers and the browser tables.
 *
 * @extends RtItem<StateBackupHistory>
 *
 * @property-read string $id Backup id (also the archive/sidecar base name)
 * @property-read string $createdAt ISO-8601 creation timestamp
 * @property-read string $env Application environment the backup was taken in
 * @property-read string $scope Backup scope value
 * @property-read list<BackupConnectionMeta> $connections Connections captured in the backup
 * @property-read int $sizeBytes Archive size in bytes
 * @property-read int $durationSeconds Wall-clock capture duration in seconds
 * @property-read bool $keep Retention pin: true excludes the backup from rotation
 * @property-read string $status Status value
 * @property-read BackupHistoryActions $actions Write operations for this index row
 */
final class BackupHistory extends RtItem
{
    /**
     * @param StateBackupHistory $state Backing runtime state
     */
    public function __construct(StateBackupHistory &$state)
    {
        parent::__construct($state);
    }

    /**
     * @param string $name Property name
     * @return string|int|bool|array<int, BackupConnectionMeta>|BackupHistoryActions Property value
     * @throws RtItemActionsClassException When item actions class is missing or invalid
     * @throws RtItemPropertyNotFoundException When $name is not a declared property
     */
    public function __get(string $name): string|int|bool|array|BackupHistoryActions
    {
        return match ($name) {
            StateBackupHistory::id => $this->_state->id,
            StateBackupHistory::createdAt => $this->_state->createdAt,
            StateBackupHistory::env => $this->_state->env,
            StateBackupHistory::scope => $this->_state->scope,
            StateBackupHistory::connections => $this->_state->connections,
            StateBackupHistory::sizeBytes => $this->_state->sizeBytes,
            StateBackupHistory::durationSeconds => $this->_state->durationSeconds,
            StateBackupHistory::keep => $this->_state->keep,
            StateBackupHistory::status => $this->_state->status,
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
