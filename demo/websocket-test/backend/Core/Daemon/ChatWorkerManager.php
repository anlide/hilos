<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Core\Daemon;

use Demo\WebSocketTest\Core\Agent\ChatAgentFactory;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Exception\Worker\AgentCreationFailedException;
use Hilos\Logging\Logger\Logger;

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
     * Uses ChatAgentFactory to create chat-specific agents.
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index (optional)
     * @return AgentInterface Agent instance
     * @throws AgentCreationFailedException If agent cannot be created
     */
    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        return ChatAgentFactory::createAgent($agentType, $agentIndex);
    }

    /**
     * Worker tick implementation
     *
     * Called regularly when connected to daemon.
     * Base class already ticks all agents, so this is for worker-specific work.
     */
    protected function tick(): void
    {
        // Worker-specific tick logic (if any)
        // Agents are already ticked by base class
    }

    /**
     * Handle signal from daemon to agent
     *
     * Processes WebSocket signals (frame, handshake, close) from daemon.
     *
     * @param string $agentId Agent ID
     * @param array $signalData Signal data
     */
    protected function onSignal(string $agentId, array $signalData): void
    {
        $signalType = $signalData['signalType'] ?? '';
        $data = $signalData['data'] ?? [];

        switch ($signalType) {
            case 'frame':
                $this->handleFrameSignal($agentId, $data);
                break;

            case 'handshake':
                $this->handleHandshakeSignal($agentId, $data);
                break;

            case 'close':
                $this->handleCloseSignal($agentId, $data);
                break;

            default:
                // Unknown signal type
                break;
        }
    }

    /**
     * Handle frame signal
     *
     * @param string $agentId Agent ID
     * @param array $data Frame signal data
     */
    private function handleFrameSignal(string $agentId, array $data): void
    {
        Logger::info("Frame signal received for agent {$agentId}: " . json_encode($data));
    }

    /**
     * Handle handshake signal
     *
     * @param string $agentId Agent ID
     * @param array $data Handshake signal data
     */
    private function handleHandshakeSignal(string $agentId, array $data): void
    {
        Logger::info("Handshake signal received for agent {$agentId}: " . json_encode($data));
    }

    /**
     * Handle close signal
     *
     * @param string $agentId Agent ID
     * @param array $data Close signal data
     */
    private function handleCloseSignal(string $agentId, array $data): void
    {
        Logger::info("Close signal received for agent {$agentId}: " . json_encode($data));
    }
}
