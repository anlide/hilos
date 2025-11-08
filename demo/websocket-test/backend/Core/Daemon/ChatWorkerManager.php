<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Core\Daemon;

use Demo\WebSocketTest\Core\Agent\ChatAgentManager;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Logging\Logger\Logger;
use Hilos\Utils\Constants\SignalTypeConstants;

/**
 * ChatWorkerManager - Worker manager for chat demo
 *
 * Extends base WorkerManager to provide chat-specific agent creation.
 * All daemon connection and agent management is handled by base WorkerManager.
 */
class ChatWorkerManager extends WorkerManager
{
    /**
     * Create agent manager instance
     *
     * @return AgentManager Agent manager instance
     */
    protected function createAgentManager(): AgentManager
    {
        return new ChatAgentManager();
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

    /**
     * Handle signal from daemon to agent
     *
     * Processes WebSocket signals (frame, handshake, close, subscribe, action, unsubscribe, update_subscription) from daemon.
     *
     * @param string $agentId Agent ID
     * @param array $signalData Signal data
     */
    protected function onSignal(string $agentId, array $signalData): void
    {
        $signalType = $signalData['signalType'] ?? '';
        $data = $signalData['data'] ?? [];

        switch ($signalType) {
            case SignalTypeConstants::PAGE_SUBSCRIBE:
                $this->handleSubscribeSignal($agentId, $data);
                break;

            case SignalTypeConstants::ACTION:
                $this->handleActionSignal($agentId, $data);
                break;

            case SignalTypeConstants::PAGE_UNSUBSCRIBE:
                $this->handleUnsubscribeSignal($agentId, $data);
                break;

            default:
                // Unknown signal type
                break;
        }
    }

    /**
     * Handle subscribe signal
     *
     * @param string $agentId Agent ID
     * @param array $data Subscribe signal data
     */
    private function handleSubscribeSignal(string $agentId, array $data): void
    {
        $clientId = $data['clientId'] ?? '';
        $page = $data['page'] ?? null;
        $groups = $data['groups'] ?? [];
        Logger::logAgentInfo($agentId, "Subscribe signal received for agent, client {$clientId}, page: " . ($page ?? 'null') . ", groups: " . json_encode($groups));
    }

    /**
     * Handle action signal
     *
     * @param string $agentId Agent ID
     * @param array $data Action signal data
     */
    private function handleActionSignal(string $agentId, array $data): void
    {
        $clientId = $data['clientId'] ?? '';
        $action = $data['action'] ?? '';
        $actionData = $data['data'] ?? [];
        Logger::logAgentInfo($agentId, "Action signal received for agent, client {$clientId}, action: {$action}, data: " . json_encode($actionData));
    }

    /**
     * Handle unsubscribe signal
     *
     * @param string $agentId Agent ID
     * @param array $data Unsubscribe signal data
     */
    private function handleUnsubscribeSignal(string $agentId, array $data): void
    {
        $clientId = $data['clientId'] ?? '';
        $page = $data['page'] ?? false;
        $groups = $data['groups'] ?? [];
        Logger::logAgentInfo($agentId, "Unsubscribe signal received for agent, client {$clientId}, page: " . ($page ? 'true' : 'false') . ", groups: " . json_encode($groups));
    }
}
