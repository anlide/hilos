<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Daemon;

use Demo\Chat\Core\Agent\ChatAgentDaemonFactory;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Agent\Exception\AgentIndexRequiredException;

/**
 * ChatAgentManagerDaemon - Agent manager daemon for chat demo (daemon side).
 *
 * Extends base AgentManagerDaemon to provide chat-specific agent daemon creation.
 */
final class ChatAgentManagerDaemon extends AgentManagerDaemon
{
    /**
     * Create agent daemon instance (factory method).
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index (optional)
     * @return AgentDaemonInterface Agent daemon instance
     * @throws AgentDaemonCreationFailedException If agent daemon cannot be created
     * @throws AgentIndexRequiredException If agent index is required but not provided
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        return ChatAgentDaemonFactory::createAgentDaemon($agentType, $agentIndex);
    }
}
