<?php

declare(strict_types=1);

namespace Demo\Chat\Socket\WebSocket\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\DTO\EntitiesChangesDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Exception\NotImplementedException;

/**
 * HandshakeResponseSignalData - Signal data for handshake response.
 *
 * Contains only the current (authorized) user in entities and current userId.
 * Full events + users are sent from MainPage on subscribe (main_page_initial).
 * Target client ID is handled by WebSocketSignalData wrapper for routing.
 */
class HandshakeResponseSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * Creates handshake response signal data.
     *
     * @param EntitiesChangesDTO $entities Entity payload (full.users with current user)
     * @param int $userId Current user ID
     * @param ?string $moderationState Current user's moderation state or null
     */
    public function __construct(
        public readonly EntitiesChangesDTO $entities,
        public readonly int $userId,
        public readonly ?string $moderationState = null,
    ) {
    }

    /**
     * Convert DTO to array for transport.
     *
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        $result = [
            'entities' => $this->entities->toArray(),
            'userId' => $this->userId,
        ];
        if ($this->moderationState !== null) {
            $result['moderationState'] = $this->moderationState;
        } else {
            $result['moderationState'] = null;
        }
        return $result;
    }

    /**
     * Create DTO from array (not implemented - response is created directly).
     *
     * @param array<string, mixed> $data Source data (ignored)
     * @return static DTO instance
     * @throws NotImplementedException Deserialization is not implemented
     */
    public static function fromArray(array $data): static
    {
        // This is not used for deserialization from array
        // Response is created directly in ChatAgent
        throw new NotImplementedException('HandshakeResponseSignalData::fromArray() is not implemented');
    }
}
