<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Agent\Daemon;

use Demo\Chat\Constants\AgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/**
 * ChatSituationGuardianAgentDaemon - Daemon proxy for ChatSituationGuardianAgent.
 *
 * Monopolistic agent for chat situation moderation. Validates messages in context.
 */
final class ChatSituationGuardianAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = AgentType::CHAT_SITUATION_GUARDIAN;

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
