<?php

declare(strict_types=1);

namespace Hilos\Core\Agent;

/**
 * AbstractAgentFactory - Abstract factory for creating agents in worker processes
 *
 * Child classes must implement createAgent() static method to create specific agent types.
 */
abstract class AbstractAgentFactory
{
    /**
     * Create agent instance based on type and index
     *
     * Factory method for creating agents. Must be implemented by child classes.
     *
     * @param string $agentType Agent type (e.g., 'chat', 'user')
     * @param ?string $agentIndex Agent index (optional, e.g., user ID)
     * @return AgentInterface|null Agent instance or null if type is not supported
     */
    abstract public static function createAgent(string $agentType, ?string $agentIndex): ?AgentInterface;
}

