<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Core\Daemon;

use Hilos\Core\Daemon\DaemonManager;

/**
 * WebSocketTestDaemon - Main daemon manager for WebSocket test project
 *
 * Extends framework DaemonManager to provide WebSocket test functionality.
 * Implements tick() method for project-specific logic.
 */
class WebSocketTestDaemon extends DaemonManager
{
    /** @var float Last heartbeat timestamp in milliseconds */
    private float $lastHeartbeat = 0.0;

    /** @var float Heartbeat interval in milliseconds (5 seconds) */
    private float $heartbeatInterval = 5000.0;

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
            $this->logMessage("WebSocketTest Daemon heartbeat - " . date('Y-m-d H:i:s'));
            $this->lastHeartbeat = $currentTime;
        }

        // TODO: Add WebSocket test specific logic here
        // - Check WebSocket connections
        // - Process messages
        // - Manage agents/workers if needed
    }
}

