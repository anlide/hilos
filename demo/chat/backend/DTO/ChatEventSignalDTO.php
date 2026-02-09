<?php

declare(strict_types=1);

namespace Demo\Chat\DTO;

use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\DTO\EntitiesChangesDTO;
use RuntimeException;

/**
 * ChatEventSignalDTO - Signal data for chat events
 *
 * Simple pass-through of entities to frontend.
 * Event is included in entities.updates.events by the caller (addEvent).
 */
class ChatEventSignalDTO extends SignalData implements SignalDataInterface
{
    public function __construct(
        public readonly EntitiesChangesDTO $entities,
    ) {
    }

    public function toArray(): array
    {
        return ['entities' => $this->entities->toArray()];
    }

    public static function fromArray(array $data): static
    {
        throw new RuntimeException('ChatEventSignalDTO::fromArray() is not implemented');
    }
}
