<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\DTO;

use Hilos\DTO\WebSocket\WebSocketCloseSignalDTO as FrameworkWebSocketCloseSignalDTO;

/**
 * WebSocketCloseSignalDTO - DTO for WebSocket close signal
 *
 * Represents a WebSocket close signal sent from WebSocket client to chat agent.
 * Extends framework WebSocketCloseSignalDTO for chat-specific functionality.
 */
class WebSocketCloseSignalDTO extends FrameworkWebSocketCloseSignalDTO implements ChatMessageDTOInterface
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
        );
    }
}
