<?php

declare(strict_types=1);

namespace Demo\Chat\Socket\WebSocket\DTO;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO as FrameworkWebSocketHandshakeSignalDTO;

/**
 * WebSocketHandshakeSignalDTO - DTO for WebSocket handshake signal.
 *
 * Represents a WebSocket handshake signal sent from WebSocket client to chat agent.
 * Extends framework WebSocketHandshakeSignalDTO for chat-specific functionality.
 */
final class WebSocketHandshakeSignalDTO extends FrameworkWebSocketHandshakeSignalDTO
{
    /**
     * Creates DTO from array. Override returns correct child class type.
     *
     * @param array<string, mixed> $data Source data (headers, acceptKey, cookies, clientIp, queryParams)
     * @return static DTO instance
     * @throws InvalidFormatException When query params are not a string map
     */
    public static function fromArray(array $data): static
    {
        return new static(
            headers: $data[self::HEADERS] ?? [],
            acceptKey: $data[self::ACCEPT_KEY] ?? '',
            cookies: $data[self::COOKIES] ?? [],
            clientIp: $data[self::CLIENT_IP] ?? '',
            queryParams: RequestQueryParams::fromStringMap(
                is_array($data[self::QUERY_PARAMS] ?? null) ? $data[self::QUERY_PARAMS] : [],
            ),
        );
    }
}
