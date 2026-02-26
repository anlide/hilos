<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Agent;

use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Agent\Exception\AgentCreationFailedException;

/**
 * ChatAgentManager - Agent manager for chat demo (worker side)
 *
 * Extends base AgentManager to provide chat-specific agent creation.
 */
class ChatAgentManager extends AgentManager
{
    /**
     * Create agent instance (factory method)
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index (optional)
     * @return AgentInterface Agent instance
     * @throws AgentCreationFailedException If agent cannot be created
     */
    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        return ChatAgentWorkerFactory::createAgent($agentType, $agentIndex);
    }
}
