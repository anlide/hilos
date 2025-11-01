<?php

declare(strict_types=1);

namespace Hilos\Socket\Server;

use Hilos\Exception\SocketException;
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
     * @throws SocketException
     */
    public function acceptConnection(): ?WorkerClientInterface
    {
        /** @var ?WorkerClientInterface */
        return parent::acceptConnection();
    }

    /**
     * Called when a new worker client connection is accepted
     *
     * @param resource $socket Client socket
     * @return WorkerClientInterface Client instance
     */
    protected function onCreateClient($socket): WorkerClientInterface
    {
        return new WorkerClient($socket);
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

