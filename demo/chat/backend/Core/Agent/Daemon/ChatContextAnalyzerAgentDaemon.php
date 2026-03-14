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

    /**
     * Create daemon proxy for ChatContextAnalyzerAgent.
     */
    public function __construct()
    {
        Logger::debug('ChatContextAnalyzerAgentDaemon created [type=' . self::AGENT_TYPE . ']');
    }

    /**
     * Get agent type identifier.
     *
     * @return string Agent type
     */
    public function getType(): string
    {
        return self::AGENT_TYPE;
    }

    /**
     * Get agent index (null for global chat context analyzer agent).
     *
     * @return ?string Agent index or null
     */
    public function getIndex(): ?string
    {
        return null;
    }

    /**
     * Check if agent requires monopolistic worker process.
     *
     * @return bool True (chat context analyzer agent requires monopolistic worker)
     */
    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }

    /**
     * Handle message from worker agent.
     *
     * @param array<string, mixed> $data Message data from worker
     */
    public function handleWorkerMessage(array $data): void
    {
        // No-op: context updates go to BotAgents via RtSync (chatContexts), not WebSocket
    }

    /**
     * Handle message from external source (WebSocket, HTTP, etc.).
     *
     * @param array<string, mixed> $data Message data from external source
     * @return ?array<string, mixed> Response data or null
     */
    public function handleExternalMessage(array $data): ?array
    {
        return null;
    }
}
