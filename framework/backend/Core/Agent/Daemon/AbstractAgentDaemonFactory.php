<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Daemon;

use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;

/**
 * AbstractAgentDaemonFactory - Abstract factory for creating agent daemon proxies.
 *
 * Child classes must implement createAgentDaemon() static method to create specific agent daemon types.
 */
abstract class AbstractAgentDaemonFactory
{
    /**
     * Create agent daemon instance based on type and index.
     *
     * Factory method for creating agent daemon proxies. Must be implemented by child classes.
     * Should throw AgentDaemonCreationFailedException if agent type is not supported.
     *
     * @param string $agentType Agent type (e.g., 'chat', 'user')
     * @param ?string $agentIndex Agent index (optional, e.g., user ID)
     * @return AgentDaemonInterface Agent daemon instance
     * @throws AgentDaemonCreationFailedException If agent daemon cannot be created
     */
    abstract public static function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface;
}
