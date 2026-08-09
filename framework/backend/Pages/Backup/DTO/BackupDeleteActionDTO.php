<?php

declare(strict_types=1);

namespace Hilos\Pages\Backup\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * DTO for the backup_delete action payload: the id of the stored backup to delete.
 */
final class BackupDeleteActionDTO extends ActionPayloadDTO
{
    /** Payload key: the target backup id (also the archive/sidecar base name). */
    public const string backupId = 'backupId';

    /**
     * @param string $backupId Target backup id
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
        return HilosSignalConstants::BACKUP_DELETE;
    }

    /**
     * @param array<string, mixed> $data Raw payload (may contain a FIELD_DATA wrapper)
     * @return static Instance
     * @throws InvalidFormatException When a field the action needs is absent or not a string
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
        return [self::backupId => $this->backupId];
    }
}
