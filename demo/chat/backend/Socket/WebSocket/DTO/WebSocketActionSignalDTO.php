<?php

declare(strict_types=1);

namespace Demo\Chat\Socket\WebSocket\DTO;

use Demo\Chat\DTO\ChatMessageDTOInterface;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO as FrameworkWebSocketActionSignalDTO;

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
            acceptKey: $data[self::ACCEPT_KEY] ?? '',
            action: $data[self::ACTION] ?? '',
            data: $data[self::DATA] ?? [],
        );
    }
}
