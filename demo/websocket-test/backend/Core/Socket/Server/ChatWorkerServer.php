<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Core\Socket\Server;

use Hilos\Exception\Worker\AgentDaemonCreationFailedException;
use Hilos\Exception\Worker\AgentNotFoundException;
use Hilos\Exception\Worker\AgentNotLinkedToWorkerException;
use Hilos\Exception\Worker\NoSuitableWorkerException;
use Hilos\Exception\Worker\WorkerClientNotFoundException;
use Hilos\Socket\Server\WorkerServer;

/**
 * ChatWorkerServer - Worker server with chat-specific agent daemon factory
 *
 * Extends WorkerServer to provide chat-specific agent daemon creation.
 */
class ChatWorkerServer extends WorkerServer
{
    /**
     * Called when server is started
     */
    protected function onStart(): void
    {
        // Server initialization - workers are not ready yet
    }

    /**
     * Called when initial workers are ready
     *
     * Sends start signal to chat agent when workers are ready.
     * @throws AgentDaemonCreationFailedException
     * @throws AgentNotFoundException
     * @throws AgentNotLinkedToWorkerException
     * @throws WorkerClientNotFoundException
     * @throws NoSuitableWorkerException
     */
    protected function onInitialWorkersReady(): void
    {
        $this->sendSignalToAgent('chat', null, ['action' => 'start']);
    }
}
