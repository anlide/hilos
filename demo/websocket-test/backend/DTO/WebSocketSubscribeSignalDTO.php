<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\DTO;

use Hilos\DTO\WebSocket\WebSocketSubscribeSignalDTO as FrameworkWebSocketSubscribeSignalDTO;

/**
 * WebSocketSubscribeSignalDTO - DTO for WebSocket subscribe signal
 *
 * Represents a subscription signal sent from WebSocket client to chat agent.
 * Extends framework WebSocketSubscribeSignalDTO for chat-specific functionality.
 */
class WebSocketSubscribeSignalDTO extends FrameworkWebSocketSubscribeSignalDTO implements ChatMessageDTOInterface
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
            page: $data[self::PAGE] ?? null,
            groups: $data[self::GROUPS] ?? [],
            params: $data[self::PARAMS] ?? [],
        );
    }
}
