<?php

declare(strict_types=1);

namespace Demo\Chat\Socket\WebSocket\DTO;

use Demo\Chat\DTO\ChatMessageDTOInterface;
use Hilos\Socket\WebSocket\DTO\WebSocketFrameBinarySignalDTO as FrameworkWebSocketFrameBinarySignalDTO;

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
            acceptKey: $data[self::ACCEPT_KEY] ?? '',
            payload: $data[self::PAYLOAD] ?? '',
        );
    }
}
