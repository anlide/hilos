<?php

declare(strict_types=1);

namespace Hilos\Pages\Backup\DTO;

use Hilos\Backup\BackupScope;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * DTO for the backup_create action payload: the scope of the backup to start.
 *
 * The scope is validated against {@see BackupScope} on the page
 * before the create is routed to the monopoly backup agent.
 */
final class BackupCreateActionDTO extends ActionPayloadDTO
{
    /** Payload key: the {@see BackupScope} value the run captures. */
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
     * @throws InvalidFormatException When a field the action needs is absent or not a string
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            $inner = [];
        }

        return new static(
            scope: trim(self::requireString($inner, self::scope)),
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
