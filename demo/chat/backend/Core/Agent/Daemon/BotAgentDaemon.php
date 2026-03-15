<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Agent\Daemon;

use Demo\Chat\Constants\AgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\Agent\Exception\AgentIndexRequiredException;
use Hilos\Utils\Logger;

/**
 * BotAgentDaemon - Daemon proxy for BotAgent.
 *
 * Simple proxy class for bot agent on daemon side.
 * One daemon per bot (agentIndex = bot.id).
 */
class BotAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = AgentType::BOT;

    /**
     * Creates daemon proxy for BotAgent with given bot id as index.
     *
     * @param string $agentIndex Bot id (non-empty)
     * @throws AgentIndexRequiredException When agentIndex is empty
     */
    public function __construct(string $agentIndex)
    {
        if ($agentIndex === '') {
            throw new AgentIndexRequiredException('BotAgentDaemon requires non-empty agentIndex (bot id)');
        }
        $this->agentIndex = $agentIndex;
        Logger::debug("BotAgentDaemon created [type=" . self::AGENT_TYPE . " index=" . $agentIndex . "]");
    }

    /**
     * Check if agent requires monopolistic worker process.
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
     * Handle message from worker agent.
     *
     * Bot signals (BOT_JOINED, BOT_LEFT, MODERATE_BOT_REQUEST) go agent-to-agent via
     * framework signal router to ChatAgent/ModeratorAgent. No daemon routing needed.
     *
     * @param array<string, mixed> $data Message data from worker
     */
    public function handleWorkerMessage(array $data): void
    {
        // No-op: bot agent signals are routed by framework to ChatAgent/ModeratorAgent
    }

    /**
     * Handle message from external source (WebSocket, HTTP, etc.).
     *
     * @param array<string, mixed> $data Message data from external source
     * @return ?array<string, mixed> Response data or null
     */
    public function handleExternalMessage(array $data): ?array
    {
        // No-op: bots receive context via RtSync from ChatContextAnalyzerAgent
        return null;
    }
}
