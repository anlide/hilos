<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Socket\Client;

use Demo\Chat\Constants\ChatSignalConstants;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Socket\Client\WebSocketClient;

/**
 * ChatWebSocketClient - WebSocket client for chat demo.
 *
 * Handles chat-specific WebSocket frame processing and sends signals to chat agent.
 */
class ChatWebSocketClient extends WebSocketClient
{
    /**
     * Hook: validate action name from parsed payload.
     *
     * @param string $actionName Action name from WebSocket payload
     * @throws AgentUnknownActionException When action name is not allowed
     */
    protected function onActionValidated(string $actionName): void
    {
        if (!in_array($actionName, [
            ChatSignalConstants::RENAME,
            ChatSignalConstants::MESSAGE,
            ChatSignalConstants::FILE,
            ChatSignalConstants::TABLE_REFRESH,
            ChatSignalConstants::USER_UPDATE,
            ChatSignalConstants::BOT_CREATE,
            ChatSignalConstants::BOT_UPDATE,
            ChatSignalConstants::BOT_DELETE,
            ChatSignalConstants::MODERATOR_PIECE_CREATE,
            ChatSignalConstants::MODERATOR_PIECE_UPDATE,
            ChatSignalConstants::MODERATOR_PIECE_DELETE,
        ], true)) {
            throw new AgentUnknownActionException("Unknown websocket action type: {$actionName}");
        }
    }

    /**
     * Called when WebSocket handshake is completed.
     *
     * @param array<string, string> $headers All HTTP headers from handshake request
     * @param string $acceptKey Sec-WebSocket-Accept value (can be used as connection identifier)
     * @param array<string, string> $cookies Parsed cookies
     * @param string $clientIp Client IP address
     * @param array<string, mixed> $queryParams Query parameters from request URL
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
