<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Core\Daemon;

use Demo\WebSocketTest\Core\Router\ChatSignalRouter;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Daemon\DaemonManager;

/**
 * ChatDaemonManager - Main daemon manager for chat demo
 *
 * Extends framework DaemonManager to provide chat functionality.
 * Implements tick() method for project-specific logic.
 * Manages signal routing and server coordination.
 */
class ChatDaemonManager extends DaemonManager
{
    /**
     * Constructor
     *
     * Creates ChatSignalRouter instance. It will be automatically
     * set to all servers registered via registerServer().
     * Sets shutdown timeout to 10 seconds.
     */
    public function __construct()
    {
        parent::__construct();

        $this->signalRouter = new ChatSignalRouter();
        $this->shutdownTimeout = 10.0;
    }

    /**
     * Create agent manager daemon instance
     *
     * @return AgentManagerDaemon Agent manager daemon instance
     */
    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new ChatAgentManagerDaemon();
    }

    /**
     * Daemon tick implementation
     *
     * This is where project-specific logic would be implemented.
     */
    protected function onTick(): void
    {
        // TODO: Add chat-specific logic here
        // - Check WebSocket connections
        // - Process messages
        // - Manage agents/workers if needed
    }
}
