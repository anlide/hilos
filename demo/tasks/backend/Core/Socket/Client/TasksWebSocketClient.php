<?php

declare(strict_types=1);

namespace Demo\Tasks\Core\Socket\Client;

use Demo\Tasks\Hilos;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Socket\Client\WebSocketClient;

/**
 * TasksWebSocketClient - WebSocket client for the tasks demo.
 *
 * Validates incoming actions against the project topology; the framework base
 * class handles the handshake (welcome frame, connection id mint) itself.
 *
 * BOTH route maps are consulted, and the agent one is not optional: a command
 * addressed to an agent rather than to a page never appears among the page
 * routes, so a client that checked only those would refuse every one of them at
 * the socket - before any agent had a chance to answer. Sign-in is entirely made
 * of such commands (HIL-623), and so is signing out (HIL-710).
 */
final class TasksWebSocketClient extends WebSocketClient
{
    /**
     * Hook: validate action name from parsed payload.
     *
     * @param string $actionName Action name from WebSocket payload
     * @throws AgentUnknownActionException When action name is not allowed
     */
    protected function onActionValidated(string $actionName): void
    {
        if (
            !array_key_exists($actionName, Hilos::getPageActionRoutes())
            && !array_key_exists($actionName, Hilos::getAgentActionRoutes())
        ) {
            throw new AgentUnknownActionException("Unknown websocket action type: {$actionName}");
        }
    }

    /**
     * Called when WebSocket handshake is completed.
     *
     * @param array<string, string> $headers All HTTP headers from handshake request (lowercase header names)
     * @param string $acceptKey Daemon-minted connection identifier
     * @param array<string, string> $cookies Parsed cookies
     * @param ?string $clientIp Client IP address, or null when the peer name is unavailable
     * @param RequestQueryParams $queryParams Query parameters from request URL
     */
    protected function onHandshake(
        array $headers,
        string $acceptKey,
        array $cookies,
        ?string $clientIp,
        RequestQueryParams $queryParams,
    ): void {
        // No additional handshake handling needed for the tasks demo
    }
}
