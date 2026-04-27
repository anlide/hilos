<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Router\DTO;

use Hilos\Core\Router\DTO\FrontendChangesDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalDataInterface;

/**
 * Payload for runtime-derived user presence updates.
 */
final class UserPresenceSignalData extends SignalData implements SignalDataInterface
{
    /**
     * @param array<string, mixed> $frontend Serialized {@see FrontendChangesDTO} payload
     */
    private function __construct(
        private readonly array $frontend,
    ) {
        parent::__construct($this->toArray());
    }

    /**
     * Creates the payload from frontend state changes before worker-daemon serialization.
     *
     * @param FrontendChangesDTO $frontend User presence and connection state update
     * @return self
     */
    public static function fromFrontendChanges(FrontendChangesDTO $frontend): self
    {
        return new self($frontend->toArray());
    }

    /**
     * Converts DTO to transport payload.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['frontend' => $this->frontend];
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
            isset($data['frontend']) && is_array($data['frontend']) ? $data['frontend'] : [],
        );
    }
}
