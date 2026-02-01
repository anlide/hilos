<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\DTO;

use Hilos\DTO\WebSocket\WebSocketFrameSignalDTO as FrameworkWebSocketFrameSignalDTO;

/**
 * WebSocketFrameSignalDTO - DTO for WebSocket text frame signal
 *
 * Represents a WebSocket text frame signal sent from WebSocket client to chat agent.
 * Extends framework WebSocketFrameSignalDTO for chat-specific functionality.
 */
class WebSocketFrameSignalDTO extends FrameworkWebSocketFrameSignalDTO implements ChatMessageDTOInterface
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
