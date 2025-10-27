<?php

declare(strict_types=1);

namespace Hilos\Socket\Server;

use Hilos\Socket\Client\HttpClient;

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
     * @return ?HttpClient New client or null
     */
    public function acceptConnection(): ?HttpClient
    {
        if (!$this->isRunning) {
            return null;
        }

        $clientSocket = @socket_accept($this->serverSocket);
        if ($clientSocket === false) {
            return null;
        }

        socket_set_nonblock($clientSocket);
        $client = new HttpClient($clientSocket);
        $this->clients[] = $client;

        return $client;
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

