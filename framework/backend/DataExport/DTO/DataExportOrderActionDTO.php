<?php

declare(strict_types=1);

namespace Hilos\DataExport\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/** Orders a copy for the person resolved from the acting browser; carries no person id. */
final class DataExportOrderActionDTO extends ActionPayloadDTO
{
    public const array SECRET_FIELDS = [];

    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_DATA_EXPORT_ORDER;
    }

    /**
     * Create from array.
     *
     * @param array<string, mixed> $data Payload data (ignored; no fields)
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new static();
    }

    /**
     * Convert to array for transport.
     *
     * @return array<string, mixed> Empty payload
     */
    public function toArray(): array
    {
        return [];
    }
}
