<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Socket\Client;

use Demo\Chat\Constants\ChatSignalConstants;
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
     * Hook: validate action name from parsed payload.
     *
     * @param string $actionName
     */
    protected function onActionValidated(string $actionName): void
    {
        if (!in_array($actionName, [
            ChatSignalConstants::RENAME,
            ChatSignalConstants::MESSAGE,
            ChatSignalConstants::FILE,
        ], true)) {
            throw new \RuntimeException("Unknown websocket action type: {$actionName}");
        }
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
