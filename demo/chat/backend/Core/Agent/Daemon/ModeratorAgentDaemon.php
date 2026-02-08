<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Agent\Daemon;

use Demo\Chat\Constants\AgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Logging\Logger\Logger;

/**
 * ModeratorAgentDaemon - Daemon proxy for ModeratorAgent
 *
 * Simple proxy class for moderator agent on daemon side.
 * Handles routing between WebSocket clients and ModeratorAgent in worker.
 * Moderator agent requires monopolistic worker process for consistent moderation.
 */
class ModeratorAgentDaemon extends AbstractAgentDaemon
{
    /** @var string Agent type */
    private const string AGENT_TYPE = AgentType::MODERATOR;

    /**
     * ModeratorAgentDaemon constructor
     */
    public function __construct()
    {
        Logger::debug("ModeratorAgentDaemon created [type=" . self::AGENT_TYPE . "]");
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
     * Moderator agent has no index (global singleton)
     *
     * @return ?string Agent index (null for global moderator agent)
     */
    public function getIndex(): ?string
    {
        return null;
    }

    /**
     * Check if agent requires monopolistic worker process
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
