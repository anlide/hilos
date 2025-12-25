<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\DTO;

use Hilos\DTO\WebSocket\WebSocketActionSignalDTO as FrameworkWebSocketActionSignalDTO;

/**
 * WebSocketActionSignalDTO - DTO for WebSocket action signal
 *
 * Represents an action signal sent from WebSocket client to chat agent.
 * Extends framework WebSocketActionSignalDTO for chat-specific functionality.
 */
class WebSocketActionSignalDTO extends FrameworkWebSocketActionSignalDTO implements ChatMessageDTOInterface
{
    /**
     * Create DTO from array
     *
     * Override parent method to return correct child class type.
     *
     * @param array $data Source data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new self(
            clientId: $data[self::CLIENT_ID] ?? '',
            action: $data[self::ACTION] ?? '',
            data: $data[self::DATA] ?? [],
            page: $data[self::PAGE] ?? null,
            group: $data[self::GROUP] ?? null,
        );
    }
}
