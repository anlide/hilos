<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Socket\Client;

use Demo\Chat\Hilos;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Socket\Client\WebSocketClient;

/**
 * ChatWebSocketClient - WebSocket client for chat demo.
 *
 * Handles chat-specific WebSocket frame processing and sends signals to chat agent.
 */
final class ChatWebSocketClient extends WebSocketClient
{
    /**
     * Hook: validate action name from parsed payload.
     *
     * @param string $actionName Action name from WebSocket payload
     * @throws AgentUnknownActionException When action name is not allowed
     */
    protected function onActionValidated(string $actionName): void
    {
        if (!array_key_exists($actionName, Hilos::getPageActionRoutes())) {
            throw new AgentUnknownActionException("Unknown websocket action type: {$actionName}");
        }
    }

    /**
     * Called when WebSocket handshake is completed.
     *
     * @param array<string, string> $headers All HTTP headers from handshake request (lowercase header names)
     * @param string $acceptKey Daemon-minted connection identifier
     * @param array<string, string> $cookies Parsed cookies
     * @param string $clientIp Client IP address
     * @param RequestQueryParams $queryParams Query parameters from request URL
     */
    protected function onHandshake(
        array $headers,
        string $acceptKey,
        array $cookies,
        string $clientIp,
        RequestQueryParams $queryParams,
    ): void {
        // No additional handshake handling needed for chat demo
    }
}
