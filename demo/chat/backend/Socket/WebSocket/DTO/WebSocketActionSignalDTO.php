<?php

declare(strict_types=1);

namespace Demo\Chat\Socket\WebSocket\DTO;

use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO as FrameworkWebSocketActionSignalDTO;

/**
 * WebSocketActionSignalDTO - DTO for WebSocket action signal.
 *
 * Represents an action signal sent from WebSocket client to chat agent.
 * Extends framework WebSocketActionSignalDTO for chat-specific functionality.
 */
final class WebSocketActionSignalDTO extends FrameworkWebSocketActionSignalDTO
{
    /**
     * Creates DTO from array.
     *
     * Override parent method to return correct child class type.
     *
     * @param array<string, mixed> $data Source data (acceptKey, action, data)
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new static(
            acceptKey: $data[self::ACCEPT_KEY] ?? '',
            action: $data[self::ACTION] ?? '',
            data: $data[self::DATA] ?? [],
        );
    }
}
