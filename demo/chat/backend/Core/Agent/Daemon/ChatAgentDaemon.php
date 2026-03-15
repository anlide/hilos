<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Agent\Daemon;

use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/**
 * ChatAgentDaemon - Daemon proxy for ChatAgent.
 *
 * Simple proxy class for chat agent on daemon side.
 * Handles routing between WebSocket clients and ChatAgent in worker.
 */
final class ChatAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = 'chat';

    /**
     * Check if agent requires monopolistic worker process.
     *
     * Chat agent requires monopolistic worker.
     *
     * @return bool True (chat agent requires monopolistic worker)
     */
    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }
}
