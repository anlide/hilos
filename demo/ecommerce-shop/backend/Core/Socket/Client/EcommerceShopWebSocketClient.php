<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Core\Socket\Client;

use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Pages\DTO\CartStepQuantityActionDTO;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Socket\Client\WebSocketClient;

/**
 * EcommerceShopWebSocketClient - WebSocket client for the ecommerce-shop demo.
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
final class EcommerceShopWebSocketClient extends WebSocketClient
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
     * Admit only the flat cart step payload before it reaches IPC.
     *
     * @param string $actionName Registered action name
     * @param mixed $actionData Decoded browser payload
     * @throws InvalidFormatException When the cart step payload has the wrong shape or values
     */
    protected function validateInboundActionData(string $actionName, mixed $actionData): void
    {
        if ($actionName !== EcommerceShopSignalConstants::CART_STEP_QUANTITY) {
            return;
        }

        if (!is_array($actionData) || count($actionData) !== 3) {
            throw new InvalidFormatException('Cart quantity action must carry exactly three fields');
        }

        CartStepQuantityActionDTO::fromArray($actionData);
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
        // No additional handshake handling needed for the ecommerce-shop demo
    }
}
