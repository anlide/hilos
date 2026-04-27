<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Router\DTO;

use Hilos\Core\Router\DTO\FrontendChangesDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalDataInterface;

/**
 * Payload for the Hilos single-user page subscription response.
 */
final class HilosUserSubscriptionSignalData extends SignalData implements SignalDataInterface
{
    /**
     * @param int $userId Requested user id from the route
     * @param array<string, mixed> $frontend Serialized {@see FrontendChangesDTO} payload
     */
    private function __construct(
        public readonly int $userId,
        private readonly array $frontend,
    ) {
        parent::__construct($this->toArray());
    }

    /**
     * Creates the payload from frontend state changes before worker-daemon serialization.
     *
     * @param int $userId Requested user id from the route
     * @param FrontendChangesDTO $frontend Frontend state snapshot for the requested user
     * @return self
     */
    public static function fromFrontendChanges(int $userId, FrontendChangesDTO $frontend): self
    {
        return new self($userId, $frontend->toArray());
    }

    /**
     * Converts DTO to transport payload.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'userId' => $this->userId,
            'frontend' => $this->frontend,
        ];
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
            isset($data['userId']) && is_int($data['userId']) ? $data['userId'] : 0,
            isset($data['frontend']) && is_array($data['frontend']) ? $data['frontend'] : [],
        );
    }
}
