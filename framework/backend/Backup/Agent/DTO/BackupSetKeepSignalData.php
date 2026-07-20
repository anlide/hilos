<?php

declare(strict_types=1);

namespace Hilos\Backup\Agent\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * BackupSetKeepSignalData - page → BackupAgent payload for BACKUP_AGENT_SET_KEEP.
 *
 * Carries the target backup id and the desired keep pin; the agent atomically
 * rewrites the sidecar (files=truth) and re-mirrors the runtime index.
 */
final class BackupSetKeepSignalData extends BaseDTO implements SignalDataInterface
{
    /** Payload key: the target backup id. */
    public const string backupId = 'backupId';

    /** Payload key: the desired keep pin. */
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
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            self::backupId => $this->backupId,
            self::keep => $this->keep,
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new static(
            backupId: (string)($data[self::backupId] ?? ''),
            keep: (bool)($data[self::keep] ?? false),
        );
    }
}
