<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\DTO;

use Hilos\DTO\WebSocket\WebSocketFrameBinarySignalDTO as FrameworkWebSocketFrameBinarySignalDTO;

/**
 * WebSocketFrameBinarySignalDTO - DTO for WebSocket binary frame signal
 *
 * Represents a WebSocket binary frame signal sent from WebSocket client to chat agent.
 * Extends framework WebSocketFrameBinarySignalDTO for chat-specific functionality.
 */
class WebSocketFrameBinarySignalDTO extends FrameworkWebSocketFrameBinarySignalDTO implements ChatMessageDTOInterface
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
            payload: $data[self::PAYLOAD] ?? '',
        );
    }
}
