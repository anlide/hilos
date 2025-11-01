<?php

declare(strict_types=1);

namespace Hilos\Socket\Server;

use Hilos\Exception\SocketException;
use Hilos\Socket\Client\HttpClient;
use Hilos\Socket\Client\Interface\HttpClientInterface;

/**
 * HttpServer - HTTP server implementation
 *
 * Manages HTTP server socket and accepts incoming connections.
 * Works with epoll in daemon main loop.
 */
class HttpServer extends AbstractServer
{
    /**
     * Accept new connection
     *
     * @return ?HttpClientInterface New client or null
     * @throws SocketException
     */
    public function acceptConnection(): ?HttpClientInterface
    {
        /** @var ?HttpClientInterface */
        return parent::acceptConnection();
    }

    /**
     * Called when a new HTTP client connection is accepted
     *
     * @param resource $socket Client socket
     * @return HttpClientInterface Client instance
     */
    protected function onCreateClient($socket): HttpClientInterface
    {
        return new HttpClient($socket);
    }

    /**
     * Get server name for logging
     *
     * @return string Server name
     */
    public function getServerName(): string
    {
        return "HTTP Server";
    }
}

