<?php

declare(strict_types=1);

namespace Hilos\Socket\Server;

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
     * @return ?WorkerClient New worker client or null
     */
    public function acceptConnection(): ?WorkerClient
    {
        if (!$this->isRunning) {
            return null;
        }

        $workerSocket = @socket_accept($this->serverSocket);
        if ($workerSocket === false) {
            return null;
        }

        socket_set_nonblock($workerSocket);
        $worker = new WorkerClient($workerSocket);
        $this->clients[] = $worker;

        return $worker;
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

