<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Agent\Daemon;

use Demo\Chat\Constants\AgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/**
 * ChatContextAnalyzerAgentDaemon - Daemon proxy for ChatContextAnalyzerAgent.
 *
 * Monopolistic agent for chat context analysis. Maintains shared context for all bots.
 */
final class ChatContextAnalyzerAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = AgentType::CHAT_CONTEXT_ANALYZER;

    /**
     * Check if agent requires monopolistic worker process.
     *
     * @return bool True (chat context analyzer agent requires monopolistic worker)
     */
    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }
}
