<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Agent\Daemon;

use Demo\Chat\Constants\AgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/**
 * ModeratorAgentDaemon - Daemon proxy for ModeratorAgent.
 *
 * Simple proxy class for moderator agent on daemon side.
 * Handles routing between WebSocket clients and ModeratorAgent in worker.
 * Moderator agent requires monopolistic worker process for consistent moderation.
 */
final class ModeratorAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = AgentType::MODERATOR;

    /**
     * Check if agent requires monopolistic worker process.
     *
     * Moderator agent requires monopolistic worker for consistent moderation.
     *
     * @return bool True (moderator agent requires monopolistic worker)
     */
    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }
}
