<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Core\Daemon;

use Demo\WebSocketTest\Core\Agent\ChatAgentManager;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Logging\Logger\Logger;
use Hilos\Utils\Constants\SignalConstants;

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
            case SignalConstants::SIGNAL_FRAME:
                $this->handleFrameSignal($agentId, $data);
                break;

            case SignalConstants::SIGNAL_HANDSHAKE:
                $this->handleHandshakeSignal($agentId, $data);
                break;

            case SignalConstants::SIGNAL_CLOSE:
                $this->handleCloseSignal($agentId, $data);
                break;

            case SignalConstants::SIGNAL_SUBSCRIBE_PAGE:
                $this->handleSubscribeSignal($agentId, $data);
                break;

            case SignalConstants::SIGNAL_ACTION:
                $this->handleActionSignal($agentId, $data);
                break;

            case SignalConstants::SIGNAL_UNSUBSCRIBE_PAGE:
                $this->handleUnsubscribeSignal($agentId, $data);
                break;

            case SignalConstants::SIGNAL_UPDATE_SUBSCRIPTION_PAGE:
                $this->handleUpdateSubscriptionSignal($agentId, $data);
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
        Logger::info("Subscribe signal received for agent {$agentId}, client {$clientId}, page: " . ($page ?? 'null') . ", groups: " . json_encode($groups));
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
        Logger::info("Action signal received for agent {$agentId}, client {$clientId}, action: {$action}, data: " . json_encode($actionData));
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
        Logger::info("Unsubscribe signal received for agent {$agentId}, client {$clientId}, page: " . ($page ? 'true' : 'false') . ", groups: " . json_encode($groups));
    }

    /**
     * Handle update subscription signal
     *
     * @param string $agentId Agent ID
     * @param array $data Update subscription signal data
     */
    private function handleUpdateSubscriptionSignal(string $agentId, array $data): void
    {
        $clientId = $data['clientId'] ?? '';
        $page = $data['page'] ?? null;
        $groups = $data['groups'] ?? null;
        Logger::info("Update subscription signal received for agent {$agentId}, client {$clientId}, page: " . ($page ?? 'null') . ", groups: " . ($groups !== null ? json_encode($groups) : 'null'));
    }
}
