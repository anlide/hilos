<?php

declare(strict_types=1);

namespace Hilos\Core\Agent;

use Hilos\Core\Router\SignalRouter;
use Hilos\Exception\Worker\AgentCreationFailedException;

/**
 * AbstractAgentFactory - Abstract factory for creating agents in worker processes
 *
 * Child classes must implement createAgent() static method to create specific agent types.
 */
abstract class AbstractAgentWorkerFactory
{
    /**
     * Create agent instance based on type and index
     *
     * Factory method for creating agents. Must be implemented by child classes.
     * Should throw AgentCreationFailedException if agent type is not supported.
     *
     * @param string $agentType Agent type (e.g., 'chat', 'user')
     * @param ?string $agentIndex Agent index (optional, e.g., user ID)
     * @param SignalRouter $signalRouter Signal router instance
     * @return AgentInterface Agent instance
     * @throws AgentCreationFailedException If agent cannot be created
     */
    abstract public static function createAgent(string $agentType, ?string $agentIndex, SignalRouter $signalRouter): AgentInterface;
}
