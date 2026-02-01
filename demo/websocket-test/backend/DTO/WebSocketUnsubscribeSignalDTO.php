<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\DTO;

use Hilos\DTO\WebSocket\WebSocketUnsubscribeSignalDTO as FrameworkWebSocketUnsubscribeSignalDTO;

/**
 * WebSocketUnsubscribeSignalDTO - DTO for WebSocket unsubscribe signal
 *
 * Represents an unsubscribe signal sent from WebSocket client to chat agent.
 * Extends framework WebSocketUnsubscribeSignalDTO for chat-specific functionality.
 */
class WebSocketUnsubscribeSignalDTO extends FrameworkWebSocketUnsubscribeSignalDTO implements ChatMessageDTOInterface
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
            page: $data[self::PAGE] ?? false,
            groups: $data[self::GROUPS] ?? [],
        );
    }
}
