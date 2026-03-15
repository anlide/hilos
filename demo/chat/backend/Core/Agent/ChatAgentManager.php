<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Agent;

use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Agent\Exception\AgentCreationFailedException;
use Hilos\Core\Agent\Exception\AgentIndexRequiredException;

/**
 * ChatAgentManager - Agent manager for chat demo (worker side).
 *
 * Extends base AgentManager to provide chat-specific agent creation.
 */
class ChatAgentManager extends AgentManager
{
    /**
     * Create agent instance (factory method).
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index (optional)
     * @return AgentInterface Agent instance
     * @throws AgentIndexRequiredException If agent index is required but not provided
     */
    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        return ChatAgentWorkerFactory::createAgent($agentType, $agentIndex);
    }
}
