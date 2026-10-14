<?php

declare(strict_types=1);

namespace Hilos\Pages\Maintenance\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/** The server supplies the acting browser and the manual window; the client chooses neither. */
final class MaintenancePassActionDTO extends ActionPayloadDTO
{
    public const array SECRET_FIELDS = [];

    /** @return string Browser action name */
    public function getAction(): string
    {
        return HilosSignalConstants::MAINTENANCE_PASS;
    }

    /**
     * @param array<string, mixed> $data Browser payload (ignored)
     * @return static Empty code request
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
