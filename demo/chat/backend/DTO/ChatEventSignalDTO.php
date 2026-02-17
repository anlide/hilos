<?php

declare(strict_types=1);

namespace Demo\Chat\DTO;

use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Table\DTO\TablesPayloadDTO;
use Hilos\DTO\EntitiesChangesDTO;
use RuntimeException;

/**
 * ChatEventSignalDTO - Signal data for chat events
 *
 * Simple pass-through of entities to frontend.
 * Event is included in entities.updates.events by the caller (addEvent).
 * Optional tables payload for subscription responses (e.g. admin page with users table).
 */
class ChatEventSignalDTO extends SignalData implements SignalDataInterface
{
    public function __construct(
        public readonly EntitiesChangesDTO $entities,
        public readonly ?TablesPayloadDTO $tables = null,
    ) {
    }

    public function toArray(): array
    {
        $data = ['entities' => $this->entities->toArray()];
        if ($this->tables !== null) {
            $data['tables'] = $this->tables->toArray();
        }
        return $data;
    }

    public static function fromArray(array $data): static
    {
        throw new RuntimeException('ChatEventSignalDTO::fromArray() is not implemented');
    }
}
