<?php

declare(strict_types=1);

namespace Hilos\Socket\Server;

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
     */
    public function acceptConnection(): ?HttpClientInterface
    {
        return parent::acceptConnection();
    }

    /**
     * Create HTTP client instance
     *
     * @param resource $socket Client socket
     * @return HttpClientInterface Client instance
     */
    protected function createClient($socket): HttpClientInterface
    {
        return new HttpClient($socket);
    }

    /**
     * Get backlog size for listen
     *
     * @return int Backlog size
     */
    protected function getBacklogSize(): int
    {
        return 5;
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

