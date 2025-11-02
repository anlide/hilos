<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Core\Daemon;

use Demo\WebSocketTest\Core\Agent\ChatAgent;
use Demo\WebSocketTest\Core\Agent\UserAgent;
use Demo\WebSocketTest\Utils\Constants\AgentType;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Daemon\WorkerManager;

/**
 * ChatWorkerManager - Worker manager for chat demo
 *
 * Extends base WorkerManager to provide chat-specific agent creation.
 * All daemon connection and agent management is handled by base WorkerManager.
 */
class ChatWorkerManager extends WorkerManager
{
    /**
     * Create agent instance based on type
     *
     * Factory method for creating chat-specific agents.
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index (optional)
     * @return AgentInterface|null Agent instance or null if type is not supported
     */
    protected function createAgent(string $agentType, ?string $agentIndex): ?AgentInterface
    {
        if ($agentType === AgentType::CHAT) {
            // Chat agent (monopolistic)
            return new ChatAgent();
        } elseif ($agentType === AgentType::USER) {
            // User agent (regular) - requires user ID as index
            if ($agentIndex !== null && $agentIndex !== '') {
                return new UserAgent($agentIndex);
            }
        }

        return null;
    }

    /** @var float Last heartbeat timestamp in milliseconds */
    private float $lastHeartbeat = 0.0;

    /** @var float Heartbeat interval in milliseconds (5 seconds) */
    private const float HEARTBEAT_INTERVAL = 5000.0; // 5 seconds

    /**
     * Worker tick implementation with heartbeat
     *
     * Called regularly when connected to daemon.
     * Base class already ticks all agents, so this is for worker-specific work.
     */
    protected function tick(): void
    {
        $currentTime = microtime(true) * 1000;

        // Send heartbeat every 5 seconds
        if (($currentTime - $this->lastHeartbeat) >= self::HEARTBEAT_INTERVAL) {
            $this->logMessage("Worker #{$this->workerIndex} heartbeat - " . date('Y-m-d H:i:s'));
            $this->lastHeartbeat = $currentTime;
        }

        // Worker-specific tick logic (if any)
        // Agents are already ticked by base class
    }
}

