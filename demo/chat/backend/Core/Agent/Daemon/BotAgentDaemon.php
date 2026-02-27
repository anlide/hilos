<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Agent\Daemon;

use Demo\Chat\Constants\AgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Utils\Logger;

/**
 * BotAgentDaemon - Daemon proxy for BotAgent
 *
 * Simple proxy class for bot agent on daemon side.
 * One daemon per bot (agentIndex = bot.id).
 */
class BotAgentDaemon extends AbstractAgentDaemon
{
    /** @var string Agent type */
    private const string AGENT_TYPE = AgentType::BOT;

    /** @var string Agent index (bot id) */
    private string $agentIndex;

    public function __construct(string $agentIndex)
    {
        if ($agentIndex === '') {
            throw new \RuntimeException('BotAgentDaemon requires non-empty agentIndex (bot id)');
        }
        $this->agentIndex = $agentIndex;
        Logger::debug("BotAgentDaemon created [type=" . self::AGENT_TYPE . " index=" . $agentIndex . "]");
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
     * Bot agent index is the bot id (string)
     *
     * @return string Agent index
     */
    public function getIndex(): ?string
    {
        return $this->agentIndex;
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
