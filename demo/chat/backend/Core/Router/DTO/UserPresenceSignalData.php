<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Router\DTO;

use Hilos\Core\Router\DTO\EntitiesChangesDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalDataInterface;

/**
 * Payload for runtime-derived user presence updates.
 */
final class UserPresenceSignalData extends SignalData implements SignalDataInterface
{
    /**
     * @param array<string, mixed> $entities Serialized {@see EntitiesChangesDTO} payload
     */
    private function __construct(
        private readonly array $entities,
    ) {
        parent::__construct($this->toArray());
    }

    /**
     * Creates the payload from entity changes before worker-daemon serialization.
     *
     * @param EntitiesChangesDTO $entities User entity update with computed runtime presence
     * @return self
     */
    public static function fromEntities(EntitiesChangesDTO $entities): self
    {
        return new self($entities->toArray());
    }

    /**
     * Converts DTO to transport payload.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['entities' => $this->entities];
    }

    /**
     * Rebuilds the payload after worker-daemon serialization.
     *
     * @param array<string, mixed> $data Source data
     * @return static
     */
    public static function fromArray(array $data): static
    {
        return new self(
            isset($data['entities']) && is_array($data['entities']) ? $data['entities'] : [],
        );
    }
}
