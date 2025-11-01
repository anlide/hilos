<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Core\Worker;

use Hilos\Core\Daemon\WorkerManager;

/**
 * ChatWorkerManager - Worker manager for chat demo
 *
 * Extends base WorkerManager to provide chat-specific worker functionality.
 * Implements tick() method for project-specific logic.
 */
class ChatWorkerManager extends WorkerManager
{
    /** @var float Last heartbeat timestamp in milliseconds */
    private float $lastHeartbeat = 0.0;

    /** @var float Heartbeat interval in milliseconds (5 seconds) */
    private float $heartbeatInterval = 5000.0;

    /**
     * Worker tick implementation with heartbeat functionality
     *
     * Logs heartbeat message every 5 seconds for health monitoring.
     * Uses millisecond precision for accurate timing.
     * This is where project-specific worker logic would be implemented.
     */
    protected function tick(): void
    {
        $currentTime = microtime(true) * 1000;

        // Send heartbeat every 5 seconds with millisecond precision
        if (($currentTime - $this->lastHeartbeat) >= $this->heartbeatInterval) {
            $this->logMessage("Chat Worker #{$this->workerId} heartbeat - " . date('Y-m-d H:i:s'));
            $this->lastHeartbeat = $currentTime;
        }

        // TODO: Add chat worker specific logic here
        // - Process agents
        // - Handle messages from daemon
        // - Process user sessions
    }
}

