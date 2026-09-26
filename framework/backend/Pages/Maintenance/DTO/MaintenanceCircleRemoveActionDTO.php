<?php

declare(strict_types=1);

namespace Hilos\Pages\Maintenance\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Pages\Maintenance\AbstractHilosMaintenancePage;

/**
 * DTO for the maintenance_circle_remove action payload: the key of the membership being removed.
 *
 * The row key the table handed out, not the address printed in that row. The membership is
 * what is being taken away, and it keeps its key however the address beside it is rendered -
 * or re-rendered, if the person changed it between the screen being drawn and the click. A key
 * that names no row any more is refused ({@see AbstractHilosMaintenancePage}).
 */
final class MaintenanceCircleRemoveActionDTO extends ActionPayloadDTO
{
    /** Payload key: the row key of the membership to remove. */
    public const string memberId = 'memberId';

    /**
     * @param int $memberId Row key of the membership to remove
     */
    public function __construct(
        public readonly int $memberId,
    ) {
    }

    /**
     * @return string Action name constant
     */
    public function getAction(): string
    {
        return HilosSignalConstants::MAINTENANCE_CIRCLE_REMOVE;
    }

    /**
     * @param array<string, mixed> $data Raw payload (may contain a FIELD_DATA wrapper)
     * @return static Instance
     * @throws InvalidFormatException When the member id is absent or not an integer
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            $inner = [];
        }

        return new static(
            memberId: self::requireInt($inner, self::memberId),
        );
    }

    /**
     * @return array<string, mixed> Data with the member id
     */
    public function toArray(): array
    {
        return [self::memberId => $this->memberId];
    }
}
