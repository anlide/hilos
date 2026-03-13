<?php

declare(strict_types=1);

namespace Demo\Chat\Socket\WebSocket\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\DTO\EntitiesChangesDTO;
use Hilos\Core\Router\SignalDataInterface;
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
        /** @var string|null Current user's moderation state (private, not in entities) */
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
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws RuntimeException Always - not implemented
     */
    public static function fromArray(array $data): static
    {
        // This is not used for deserialization from array
        // Response is created directly in ChatAgent
        throw new RuntimeException('HandshakeResponseSignalData::fromArray() is not implemented');
    }
}
