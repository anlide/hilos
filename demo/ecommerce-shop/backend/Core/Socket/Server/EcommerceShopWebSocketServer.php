<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Core\Socket\Server;

use Demo\EcommerceShop\Core\Socket\Client\EcommerceShopWebSocketClient;
use Hilos\Environment\Exception\EnvException;
use Hilos\Socket\Server\AbstractServer;
use Hilos\Socket\Server\WebSocketServer;

/**
 * EcommerceShopWebSocketServer - WebSocket server for the ecommerce-shop demo.
 *
 * Extends base WebSocketServer; queues signals for dispatch through Hilos::$sr.
 *
 * @extends AbstractServer<EcommerceShopWebSocketClient>
 */
final class EcommerceShopWebSocketServer extends WebSocketServer
{
    /**
     * Called when a new ecommerce-shop WebSocket client connection is accepted.
     *
     * @param resource $socket Client socket
     * @return EcommerceShopWebSocketClient Client instance
     * @throws EnvException When the client ctor reads an invalid socket read buffer env value
     */
    protected function onCreateClient($socket): EcommerceShopWebSocketClient
    {
        return new EcommerceShopWebSocketClient($socket);
    }

    /**
     * Called when server is started. No demo-specific startup logic.
     */
    protected function onStart(): void
    {
        // EcommerceShop WebSocket server has no specific startup logic
    }
}
