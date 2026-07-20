<?php

declare(strict_types=1);

namespace Hilos\Backup\Agent\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * BackupCreateSignalData - page → BackupAgent payload for BACKUP_AGENT_CREATE.
 *
 * Carries the validated scope value a manual create should capture; the agent
 * resolves it back to a {@see \Hilos\Backup\BackupScope} on the guarded create path.
 */
final class BackupCreateSignalData extends BaseDTO implements SignalDataInterface
{
    /** Payload key: the backup scope value. */
    public const string scope = 'scope';

    /**
     * @param string $scope Backup scope value
     */
    public function __construct(
        public readonly string $scope,
    ) {
    }

    /**
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [self::scope => $this->scope];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new static(
            scope: (string)($data[self::scope] ?? ''),
        );
    }
}
