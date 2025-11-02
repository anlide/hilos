<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Core\Socket\Server;

use Demo\WebSocketTest\Core\Agent\Daemon\ChatAgentDaemonFactory;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemonFactory;
use Hilos\Exception\Worker\AgentDaemonCreationFailedException;
use Hilos\Socket\Client\Interface\WorkerClientInterface;
use Hilos\Socket\Client\WorkerClient;
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
     *
     * Sends ping signal to chat agent (starts it if needed, or queues if no worker available).
     * @throws AgentDaemonCreationFailedException
     */
    protected function onStart(): void
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

