<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Core\Agent;

use Demo\WebSocketTest\Utils\Constants\AgentType;
use Hilos\Core\Agent\AbstractAgentFactory;
use Hilos\Core\Agent\AgentInterface;

/**
 * ChatAgentFactory - Factory for creating chat-specific agents in worker processes
 *
 * Creates ChatAgent and UserAgent instances based on agent type.
 */
class ChatAgentFactory extends AbstractAgentFactory
{
    /**
     * Create agent instance based on type
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index (optional)
     * @return AgentInterface|null Agent instance or null if type is not supported
     */
    public static function createAgent(string $agentType, ?string $agentIndex): ?AgentInterface
    {
        return match ($agentType) {
            AgentType::CHAT => new ChatAgent(),
            AgentType::USER => ($agentIndex !== null && $agentIndex !== '') ? new UserAgent($agentIndex) : null,
            default => null,
        };
    }
}

