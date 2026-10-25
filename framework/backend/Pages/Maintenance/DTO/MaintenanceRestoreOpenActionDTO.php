<?php

declare(strict_types=1);

namespace Hilos\Pages\Maintenance\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/** Empty browser payload for opening a restore. */
final class MaintenanceRestoreOpenActionDTO extends ActionPayloadDTO
{
    public const array SECRET_FIELDS = [];

    /** @return string Browser action name */
    public function getAction(): string
    {
        return HilosSignalConstants::MAINTENANCE_RESTORE_OPEN;
    }

    /**
     * @param array<string, mixed> $data Browser payload (ignored)
     * @return static Empty open request
     */
    public static function fromArray(array $data): static
    {
        return new static();
    }

    /** @return array<string, mixed> Empty browser payload */
    public function toArray(): array
    {
        return [];
    }
}
