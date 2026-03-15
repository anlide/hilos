<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Agent\Daemon;

use Demo\Chat\Constants\AgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Utils\Logger;

/**
 * ModeratorAgentDaemon - Daemon proxy for ModeratorAgent.
 *
 * Simple proxy class for moderator agent on daemon side.
 * Handles routing between WebSocket clients and ModeratorAgent in worker.
 * Moderator agent requires monopolistic worker process for consistent moderation.
 */
class ModeratorAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = AgentType::MODERATOR;

    /**
     * Creates daemon proxy for ModeratorAgent.
     */
    public function __construct()
    {
        Logger::debug("ModeratorAgentDaemon created [type=" . self::AGENT_TYPE . "]");
    }

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

    /**
     * Handle message from worker agent.
     *
     * Moderation results go agent-to-agent to ChatAgent via framework signal router.
     *
     * @param array<string, mixed> $data Message data from worker
     */
    public function handleWorkerMessage(array $data): void
    {
        // No-op: moderation results are routed by framework to ChatAgent
    }

    /**
     * Handle message from external source (WebSocket, HTTP, etc.).
     *
     * @param array<string, mixed> $data Message data from external source
     * @return ?array<string, mixed> Response data or null
     */
    public function handleExternalMessage(array $data): ?array
    {
        // No-op: moderation requests come from ChatAgent/BotAgent via signal router
        return null;
    }
}
