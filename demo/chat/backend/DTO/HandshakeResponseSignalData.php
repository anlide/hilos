<?php

declare(strict_types=1);

namespace Demo\Chat\DTO;

use Hilos\Core\Router\SignalDataInterface;
use Hilos\DTO\BaseDTO;
use Hilos\DTO\EntitiesChangesDTO;
use RuntimeException;

/**
 * HandshakeResponseSignalData - Signal data for handshake response
 *
 * Contains entity snapshot (events + users) and current userId.
 * Username is derived from entities.full.users on frontend.
 * Target client ID is handled by WebSocketSignalData wrapper for routing.
 */
class HandshakeResponseSignalData extends BaseDTO implements SignalDataInterface
{
    public function __construct(
        /** @var EntitiesChangesDTO Entity changes payload (full snapshot: events, users) */
        public readonly EntitiesChangesDTO $entities,
        /** @var int Current user ID */
        public readonly int $userId,
    ) {
    }

    /**
     * Convert DTO to array
     *
     * @return array DTO data as array
     */
    public function toArray(): array
    {
        return [
            'entities' => $this->entities->toArray(),
            'userId' => $this->userId,
        ];
    }

    /**
     * Create DTO from array
     *
     * @param array $data Source data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        // This is not used for deserialization from array
        // Response is created directly in ChatAgent
        throw new RuntimeException('HandshakeResponseSignalData::fromArray() is not implemented');
    }
}
