<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Agent;

use Demo\Chat\Constants\AgentType;
use Demo\Chat\Agents\BotAgent;
use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Agents\ModeratorAgent;
use Hilos\Core\Agent\AbstractAgentWorkerFactory;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Exception\Worker\AgentCreationFailedException;

/**
 * ChatAgentFactory - Factory for creating chat-specific agents in worker processes
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
     * @param SignalRouter $signalRouter Signal router instance
     * @return AgentInterface Agent instance
     * @throws AgentCreationFailedException If agent type is not supported or agentIndex is invalid
     */
    public static function createAgent(string $agentType, ?string $agentIndex, SignalRouter $signalRouter): AgentInterface
    {
        return match ($agentType) {
            AgentType::CHAT => new ChatAgent($signalRouter),
            AgentType::BOT => new BotAgent($signalRouter),
            AgentType::MODERATOR => new ModeratorAgent($signalRouter),
            default => throw new AgentCreationFailedException($agentType, $agentIndex),
        };
    }
}
