<?php

declare(strict_types=1);

namespace Hilos\Pages\Status\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/** Target shared by cancel-publication and publish-now; the envelope selects the operation. */
final class HilosStatusIncidentActionDTO extends ActionPayloadDTO
{
    public const string incidentId = 'incidentId';

    public const array SECRET_FIELDS = [];

    /**
     * @param int $incidentId Persisted incident primary id
     * @throws InvalidFormatException When the target is not positive
     */
    public function __construct(public readonly int $incidentId)
    {
        if ($incidentId <= 0) {
            throw new InvalidFormatException('Choose an incident.');
        }
    }

    /** @return string Representative action; dispatch uses the original envelope name */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_STATUS_CANCEL_PUBLICATION;
    }

    /**
     * @param array<string, mixed> $data Raw action payload, optionally wrapped
     * @return static Validated incident target
     * @throws InvalidFormatException When the data section or target is malformed
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            throw new InvalidFormatException('The incident action needs an object payload.');
        }

        return new static(self::requireInt($inner, self::incidentId));
    }

    /** @return array<string, mixed> The target without envelope metadata */
    public function toArray(): array
    {
        return [self::incidentId => $this->incidentId];
    }
}
