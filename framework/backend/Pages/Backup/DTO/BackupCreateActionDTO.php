<?php

declare(strict_types=1);

namespace Hilos\Pages\Backup\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * DTO for the backup_create action payload: the scope of the backup to start.
 *
 * The scope is validated against {@see \Hilos\Backup\BackupScope} on the page
 * before the create is routed to the monopoly backup agent.
 */
final class BackupCreateActionDTO extends ActionPayloadDTO
{
    /** Payload key: the {@see \Hilos\Backup\BackupScope} value the run captures. */
    public const string scope = 'scope';

    /**
     * @param string $scope Requested backup scope value (raw; validated on the page)
     */
    public function __construct(
        public readonly string $scope,
    ) {
    }

    /**
     * @return string Action name constant
     */
    public function getAction(): string
    {
        return HilosSignalConstants::BACKUP_CREATE;
    }

    /**
     * @param array<string, mixed> $data Raw payload (may contain a FIELD_DATA wrapper)
     * @return static Instance
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            $inner = [];
        }

        return new static(
            scope: is_string($inner[self::scope] ?? null) ? trim($inner[self::scope]) : '',
        );
    }

    /**
     * @return array<string, mixed> Data with the scope
     */
    public function toArray(): array
    {
        return [self::scope => $this->scope];
    }
}
