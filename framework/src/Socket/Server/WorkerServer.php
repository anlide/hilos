<?php

declare(strict_types=1);

namespace Hilos\Socket\Server;

use Hilos\Socket\Client\Interface\WorkerClientInterface;
use Hilos\Socket\Client\WorkerClient;

/**
 * WorkerServer - Worker communication server implementation
 *
 * Manages worker server socket and accepts incoming connections from workers.
 * Works with epoll in daemon main loop.
 */
class WorkerServer extends AbstractServer
{
    /**
     * Accept new worker connection
     *
     * @return ?WorkerClientInterface New worker client or null
     */
    public function acceptConnection(): ?WorkerClientInterface
    {
        return parent::acceptConnection();
    }

    /**
     * Create worker client instance
     *
     * @param resource $socket Client socket
     * @return WorkerClientInterface Client instance
     */
    protected function createClient($socket): WorkerClientInterface
    {
        return new WorkerClient($socket);
    }

    /**
     * Get backlog size for listen
     *
     * @return int Backlog size
     */
    protected function getBacklogSize(): int
    {
        return 10;
    }

    /**
     * Get server name for logging
     *
     * @return string Server name
     */
    public function getServerName(): string
    {
        return "Worker Server";
    }
}

