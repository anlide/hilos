<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Core\Agent\Daemon;

use Demo\WebSocketTest\Constants\AgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Logging\Logger\Logger;

/**
 * BotAgentDaemon - Daemon proxy for BotAgent
 *
 * Simple proxy class for bot agent on daemon side.
 * Handles routing between WebSocket clients and BotAgent in worker.
 */
class BotAgentDaemon extends AbstractAgentDaemon
{
    /** @var string Agent type */
    private const string AGENT_TYPE = AgentType::BOT;

    /**
     * BotAgentDaemon constructor
     */
    public function __construct()
    {
        Logger::debug("BotAgentDaemon created [type=" . self::AGENT_TYPE . "]");
    }

    /**
     * Get agent type
     *
     * @return string Agent type
     */
    public function getType(): string
    {
        return self::AGENT_TYPE;
    }

    /**
     * Get agent index
     *
     * Bot agent has no index (global singleton)
     *
     * @return ?string Agent index (null for global bot agent)
     */
    public function getIndex(): ?string
    {
        return null;
    }

    /**
     * Check if agent requires monopolistic worker process
     *
     * Bot agent does not require monopolistic worker.
     *
     * @return bool False (bot agent does not require monopolistic worker)
     */
    public function requiresMonopolisticProcess(): bool
    {
        return false;
    }

    /**
     * Handle message from worker agent
     *
     * @param array $data Message data from worker
     */
    public function handleWorkerMessage(array $data): void
    {
        // TODO: Implement routing to WebSocket clients
        // For now, this is a placeholder
    }

    /**
     * Handle message from external source (WebSocket, HTTP, etc.)
     *
     * @param array $data Message data from external source
     * @return ?array Response data
     */
    public function handleExternalMessage(array $data): ?array
    {
        // TODO: Implement routing to worker agent
        // For now, this is a placeholder
        return null;
    }
}
