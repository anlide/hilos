<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Core\Socket\Client;

use Demo\WebSocketTest\Constants\ChatSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Socket\Client\WebSocketClient;

/**
 * ChatWebSocketClient - WebSocket client for chat demo
 *
 * Handles chat-specific WebSocket frame processing and sends signals to chat agent.
 */
class ChatWebSocketClient extends WebSocketClient
{
    /**
     * Hook: resolve action name from parsed payload.
     *
     * @param string $payload
     * @param ?array<string,mixed> $decoded
     * @return ?string
     */
    protected function onActionParsed(string $payload, ?array $decoded): ?string
    {
        $actionName = ChatSignalConstants::MESSAGE;

        if (is_array($decoded) && isset($decoded[SignalPayloadConstants::FIELD_ACTION]) && is_string($decoded[SignalPayloadConstants::FIELD_ACTION])) {
            $action = strtolower($decoded[SignalPayloadConstants::FIELD_ACTION]);
            $actionName = match ($action) {
                ChatSignalConstants::RENAME => ChatSignalConstants::RENAME,
                ChatSignalConstants::MESSAGE => ChatSignalConstants::MESSAGE,
                ChatSignalConstants::FILE => ChatSignalConstants::FILE,
                default => throw new \RuntimeException("Unknown websocket action type: {$action}"),
            };
        }

        return $actionName;
    }

    /**
     * Called when WebSocket handshake is completed
     *
     * @param array $headers All HTTP headers from handshake request
     * @param string $acceptKey Sec-WebSocket-Accept value (can be used as connection identifier)
     * @param array $cookies Parsed cookies
     * @param string $clientIp Client IP address
     * @param array $queryParams Query parameters (GET parameters) from request URL
     */
    protected function onHandshake(
        array $headers,
        string $acceptKey,
        array $cookies,
        string $clientIp,
        array $queryParams,
    ): void
    {
        // No additional handshake handling needed for chat demo
    }
}
