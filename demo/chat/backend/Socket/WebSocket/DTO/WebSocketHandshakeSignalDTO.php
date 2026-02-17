<?php

declare(strict_types=1);

namespace Demo\Chat\Socket\WebSocket\DTO;

use Demo\Chat\DTO\ChatMessageDTOInterface;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO as FrameworkWebSocketHandshakeSignalDTO;

/**
 * WebSocketHandshakeSignalDTO - DTO for WebSocket handshake signal
 *
 * Represents a WebSocket handshake signal sent from WebSocket client to chat agent.
 * Extends framework WebSocketHandshakeSignalDTO for chat-specific functionality.
 */
class WebSocketHandshakeSignalDTO extends FrameworkWebSocketHandshakeSignalDTO implements ChatMessageDTOInterface
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
            headers: $data[self::HEADERS] ?? [],
            acceptKey: $data[self::ACCEPT_KEY] ?? '',
            cookies: $data[self::COOKIES] ?? [],
            clientIp: $data[self::CLIENT_IP] ?? '',
            queryParams: $data[self::QUERY_PARAMS] ?? [],
        );
    }
}
