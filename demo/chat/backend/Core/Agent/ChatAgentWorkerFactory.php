<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Agent;

use Demo\Chat\Agents\BotAgent;
use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Agents\ChatContextAnalyzerAgent;
use Demo\Chat\Agents\ModeratorAgent;
use Demo\Chat\Constants\AgentType;
use Hilos\Core\Agent\AbstractAgentWorkerFactory;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\Exception\AgentCreationFailedException;

/**
 * ChatAgentFactory - Factory for creating chat-specific agents in worker processes.
 *
 * Creates ChatAgent, BotAgent and ModeratorAgent instances based on agent type.
 */
class ChatAgentWorkerFactory extends AbstractAgentWorkerFactory
{
    /**
     * Create agent instance based on type
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index (optional)
     * @return AgentInterface Agent instance
     * @throws AgentCreationFailedException If agent type is not supported or agentIndex is invalid
     */
    public static function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        return match ($agentType) {
            AgentType::CHAT => new ChatAgent(),
            AgentType::CHAT_CONTEXT_ANALYZER => new ChatContextAnalyzerAgent(),
            AgentType::BOT => new BotAgent(
                $agentIndex ?? throw new \RuntimeException('BotAgent requires agentIndex (bot id)'),
            ),
            AgentType::MODERATOR => new ModeratorAgent(),
            default => throw new AgentCreationFailedException($agentType, $agentIndex),
        };
    }
}
