<?php

declare(strict_types=1);

namespace Demo\Chat\Socket\WebSocket\DTO;

use Hilos\Socket\WebSocket\DTO\WebSocketFrameBinarySignalDTO as FrameworkWebSocketFrameBinarySignalDTO;

/**
 * WebSocketFrameBinarySignalDTO - DTO for WebSocket binary frame signal.
 *
 * Represents a WebSocket binary frame signal sent from WebSocket client to chat agent.
 * Extends framework WebSocketFrameBinarySignalDTO for chat-specific functionality.
 */
final class WebSocketFrameBinarySignalDTO extends FrameworkWebSocketFrameBinarySignalDTO
{
    /**
     * Create DTO from array.
     *
     * Override parent method to return correct child class type.
     *
     * @param array<string, mixed> $data Source data (acceptKey, payload)
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        $base = parent::fromArray($data);

        return new static(
            acceptKey: $base->acceptKey,
            payload: $base->payload,
        );
    }
}
