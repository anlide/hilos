<?php

declare(strict_types=1);

namespace Demo\Chat\Socket\WebSocket\DTO;

use Hilos\Core\Router\DTO\EntitiesChangesDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Hilos\BaseDTO;
use RuntimeException;

/**
 * HandshakeResponseSignalData - Signal data for handshake response
 *
 * Contains only the current (authorized) user in entities and current userId.
 * Full events + users are sent from MainPage on subscribe (main_page_initial).
 * Target client ID is handled by WebSocketSignalData wrapper for routing.
 */
class HandshakeResponseSignalData extends BaseDTO implements SignalDataInterface
{
    public function __construct(
        /** @var EntitiesChangesDTO Entity payload: only current user (full.users) */
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
