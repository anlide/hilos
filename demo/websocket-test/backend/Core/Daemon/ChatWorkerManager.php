<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Core\Daemon;

use Demo\WebSocketTest\Core\Agent\ChatAgentManager;
use Demo\WebSocketTest\Core\Router\ChatSignalRouter;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Router\SignalRouter;

/**
 * ChatWorkerManager - Worker manager for chat demo
 *
 * Extends base WorkerManager to provide chat-specific agent creation.
 * All daemon connection and agent management is handled by base WorkerManager.
 */
class ChatWorkerManager extends WorkerManager
{
    /**
     * Create signal router instance
     *
     * @return SignalRouter Signal router instance
     */
    protected function createSignalRouter(): SignalRouter
    {
        return new ChatSignalRouter();
    }

    /**
     * Create agent manager instance
     *
     * @param SignalRouter $signalRouter Signal router instance
     * @return AgentManager Agent manager instance
     */
    protected function createAgentManager(SignalRouter $signalRouter): AgentManager
    {
        return new ChatAgentManager($signalRouter);
    }

    /**
     * Worker tick implementation
     *
     * Called regularly when connected to daemon.
     * Base class already ticks all agents, so this is for worker-specific work.
     */
    protected function onTick(): void
    {
        // Worker-specific tick logic (if any)
        // Agents are already ticked by base class
    }
}
