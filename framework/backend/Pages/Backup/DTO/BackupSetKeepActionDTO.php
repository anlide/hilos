<?php

declare(strict_types=1);

namespace Hilos\Pages\Backup\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * DTO for the backup_set_keep action payload: the target backup and the desired
 * keep pin. The toggle carries an explicit target value (not a flip) so the action
 * is idempotent under concurrent clicks.
 */
final class BackupSetKeepActionDTO extends ActionPayloadDTO
{
    /** Payload key: the target backup id. */
    public const string backupId = 'backupId';

    /** Payload key: the desired keep pin (true excludes the backup from rotation). */
    public const string keep = 'keep';

    /**
     * @param string $backupId Target backup id
     * @param bool $keep Desired keep pin
     */
    public function __construct(
        public readonly string $backupId,
        public readonly bool $keep,
    ) {
    }

    /**
     * @return string Action name constant
     */
    public function getAction(): string
    {
        return HilosSignalConstants::BACKUP_SET_KEEP;
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
            keep: (bool)($inner[self::keep] ?? false),
        );
    }

    /**
     * @return array<string, mixed> Data with the backup id and keep pin
     */
    public function toArray(): array
    {
        return [
            self::backupId => $this->backupId,
            self::keep => $this->keep,
        ];
    }
}
