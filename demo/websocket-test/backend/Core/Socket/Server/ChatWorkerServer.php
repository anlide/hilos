<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Core\Socket\Server;

use Demo\WebSocketTest\Core\Agent\Daemon\ChatAgentDaemonFactory;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemonFactory;
use Hilos\Exception\Worker\AgentDaemonCreationFailedException;
use Hilos\Exception\Worker\NoSuitableWorkerException;
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
     * Sends ping signal to chat agent when workers are ready.
     * @throws AgentDaemonCreationFailedException
     * @throws NoSuitableWorkerException
     */
    protected function onInitialWorkersReady(): void
    {
        $this->sendSignalToAgent('chat', null, ['action' => 'ping']);
    }

    /**
     * Get agent daemon factory class name
     *
     * @return class-string<AbstractAgentDaemonFactory> Factory class name
     */
    protected function getAgentDaemonFactoryClass(): string
    {
        return ChatAgentDaemonFactory::class;
    }
}

