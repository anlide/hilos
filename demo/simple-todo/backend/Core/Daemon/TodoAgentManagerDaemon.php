<?php

declare(strict_types=1);

namespace Demo\SimpleTodo\Core\Daemon;

use Demo\SimpleTodo\Hilos;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Agent\Exception\AgentIndexRequiredException;
use Hilos\Core\Agent\TopologyAgentFactory;

/**
 * TodoAgentManagerDaemon - Agent manager daemon for the simple-todo demo (daemon side).
 *
 * Extends base AgentManagerDaemon to provide demo-specific agent daemon creation.
 */
final class TodoAgentManagerDaemon extends AgentManagerDaemon
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
        return TopologyAgentFactory::createDaemon(Hilos::class, $agentType, $agentIndex);
    }
}
