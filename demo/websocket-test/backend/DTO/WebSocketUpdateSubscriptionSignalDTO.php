<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\DTO;

use Hilos\DTO\WebSocket\WebSocketUpdateSubscriptionSignalDTO as FrameworkWebSocketUpdateSubscriptionSignalDTO;

/**
 * WebSocketUpdateSubscriptionSignalDTO - DTO for WebSocket update subscription signal
 *
 * Represents an update subscription signal sent from WebSocket client to chat agent.
 * Extends framework WebSocketUpdateSubscriptionSignalDTO for chat-specific functionality.
 */
class WebSocketUpdateSubscriptionSignalDTO extends FrameworkWebSocketUpdateSubscriptionSignalDTO implements ChatMessageDTOInterface
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
            groups: $data[self::GROUPS] ?? null,
        );
    }
}
