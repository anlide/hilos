<?php

declare(strict_types=1);

namespace Hilos\Backup\Agent\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * BackupDeleteSignalData - page → BackupAgent payload for BACKUP_AGENT_DELETE.
 *
 * Carries the backup id to remove; the agent routes it through the shared
 * delete path and drops the matching runtime index row.
 */
final class BackupDeleteSignalData extends BaseDTO implements SignalDataInterface
{
    /** Payload key: the target backup id. */
    public const string backupId = 'backupId';

    /**
     * @param string $backupId Target backup id
     */
    public function __construct(
        public readonly string $backupId,
    ) {
    }

    /**
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [self::backupId => $this->backupId];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new static(
            backupId: (string)($data[self::backupId] ?? ''),
        );
    }
}
