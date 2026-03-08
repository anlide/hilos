<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Agent\Daemon;

use Demo\Chat\Constants\AgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Utils\Logger;

/**
 * ChatContextAnalyzerAgentDaemon - Daemon proxy for ChatContextAnalyzerAgent
 *
 * Monopolistic agent for chat context analysis. Maintains shared context for all bots.
 */
class ChatContextAnalyzerAgentDaemon extends AbstractAgentDaemon
{
    private const string AGENT_TYPE = AgentType::CHAT_CONTEXT_ANALYZER;

    public function __construct()
    {
        Logger::debug('ChatContextAnalyzerAgentDaemon created [type=' . self::AGENT_TYPE . ']');
    }

    public function getType(): string
    {
        return self::AGENT_TYPE;
    }

    public function getIndex(): ?string
    {
        return null;
    }

    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }

    public function handleWorkerMessage(array $data): void
    {
        // No-op: context updates go to BotAgents via RtSync (chatContexts), not WebSocket
    }

    public function handleExternalMessage(array $data): ?array
    {
        return null;
    }
}
