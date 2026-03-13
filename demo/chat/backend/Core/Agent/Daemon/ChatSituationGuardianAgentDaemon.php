<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Agent\Daemon;

use Demo\Chat\Constants\AgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Utils\Logger;

/**
 * ChatSituationGuardianAgentDaemon - Daemon proxy for ChatSituationGuardianAgent
 *
 * Monopolistic agent for chat situation moderation. Validates messages in context.
 */
final class ChatSituationGuardianAgentDaemon extends AbstractAgentDaemon
{
    private const string AGENT_TYPE = AgentType::CHAT_SITUATION_GUARDIAN;

    /**
     * Create daemon proxy for ChatSituationGuardianAgent.
     */
    public function __construct()
    {
        Logger::debug('ChatSituationGuardianAgentDaemon created [type=' . self::AGENT_TYPE . ']');
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
     * Get agent index (null for global chat situation guardian agent).
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
     * @return bool True (chat situation guardian agent requires monopolistic worker)
     */
    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }
}
