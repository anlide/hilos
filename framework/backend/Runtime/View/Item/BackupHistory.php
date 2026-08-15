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
 * @property-read ?string $env Application environment the backup was taken in; null when the record names none
 * @property-read ?string $scope Backup scope value; null when the record names none
 * @property-read list<BackupConnectionMeta> $connections Connections captured in the backup
 * @property-read int $sizeBytes Archive size in bytes
 * @property-read int $durationSeconds Wall-clock capture duration in seconds
 * @property-read bool $keep Retention pin: true excludes the backup from rotation
 * @property-read string $status Status value
 * @property-read ?string $failureReason Why the run failed (error rows only); null otherwise
 * @property-read int $dumpBytes Uncompressed dump volume in bytes; 0 for error and legacy rows
 * @property-read ?string $sha256 Archive digest; null for error rows and backups written before digests
 * @property-read ?string $verifiedAt ISO-8601 instant of the last verification; null means never verified
 * @property-read ?string $verifyOutcome Outcome value of that verification; null means never verified
 * @property-read ?string $restoredAt ISO-8601 instant this archive was last restored from; null means never
 * @property-read int $restoreDurationSeconds How long that restore took; 0 means never restored
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
     * @return string|int|bool|array<int, BackupConnectionMeta>|BackupHistoryActions|null Property value
     * @throws RtItemActionsClassException When item actions class is missing or invalid
     * @throws RtItemPropertyNotFoundException When $name is not a declared property
     */
    public function __get(string $name): string|int|bool|array|BackupHistoryActions|null
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
            StateBackupHistory::failureReason => $this->_state->failureReason,
            StateBackupHistory::dumpBytes => $this->_state->dumpBytes,
            StateBackupHistory::sha256 => $this->_state->sha256,
            StateBackupHistory::verifiedAt => $this->_state->verifiedAt,
            StateBackupHistory::verifyOutcome => $this->_state->verifyOutcome,
            StateBackupHistory::restoredAt => $this->_state->restoredAt,
            StateBackupHistory::restoreDurationSeconds => $this->_state->restoreDurationSeconds,
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
