<?php

declare(strict_types=1);

namespace Hilos\Pages\Backup\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * DTO for the backup_restore action payload: the archive to replay, and nothing else.
 *
 * One field on purpose. The scope is read off the index row the id names rather than
 * taken from the client, because the two must describe the same archive and only the
 * server knows how that archive was captured. The CLI flags have no counterpart here
 * either: `--force` exists for restoring into production, which this surface does not
 * offer at all, `--cold` describes a node with no daemon to talk to, and `--yes` is the
 * confirmation the modal already collected.
 */
final class BackupRestoreActionDTO extends ActionPayloadDTO
{
    /** Payload key: the archive to restore. */
    public const string backupId = 'backupId';

    /**
     * @param string $backupId Id of the archive to restore
     */
    public function __construct(
        public readonly string $backupId,
    ) {
    }

    /**
     * @return string Action name constant
     */
    public function getAction(): string
    {
        return HilosSignalConstants::BACKUP_RESTORE;
    }

    /**
     * @param array<string, mixed> $data Raw payload (may contain a FIELD_DATA wrapper)
     * @return static Instance
     * @throws InvalidFormatException When the payload names no archive to restore
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            $inner = [];
        }

        return new static(
            backupId: trim(self::requireString($inner, self::backupId)),
        );
    }

    /**
     * @return array<string, mixed> Data with the backup id
     */
    public function toArray(): array
    {
        return [
            self::backupId => $this->backupId,
        ];
    }
}
