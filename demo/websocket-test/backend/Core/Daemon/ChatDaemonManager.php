<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Core\Daemon;

use Demo\WebSocketTest\Core\Router\ChatSignalRouter;
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
    /** @var float Last heartbeat timestamp in milliseconds */
    private float $lastHeartbeat = 0.0;

    /** @var float Heartbeat interval in milliseconds (5 seconds) */
    private float $heartbeatInterval = 5000.0;

    /**
     * Constructor
     *
     * Creates ChatSignalRouter instance. It will be automatically
     * set to all servers registered via registerServer().
     */
    public function __construct()
    {
        parent::__construct();

        $this->signalRouter = new ChatSignalRouter();
    }

    /**
     * Daemon tick implementation with heartbeat functionality
     *
     * Logs heartbeat message every 5 seconds for health monitoring.
     * Uses millisecond precision for accurate timing.
     * This is where project-specific logic would be implemented.
     */
    protected function tick(): void
    {
        $currentTime = microtime(true) * 1000;

        // Send heartbeat every 5 seconds with millisecond precision
        if (($currentTime - $this->lastHeartbeat) >= $this->heartbeatInterval) {
            $this->logMessage("Chat Daemon heartbeat - " . date('Y-m-d H:i:s'));
            $this->lastHeartbeat = $currentTime;
        }

        // TODO: Add chat-specific logic here
        // - Check WebSocket connections
        // - Process messages
        // - Manage agents/workers if needed
    }
}

