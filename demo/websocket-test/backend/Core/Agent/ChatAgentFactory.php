<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Core\Agent;

use Demo\WebSocketTest\Constants\AgentType;
use Hilos\Core\Agent\AbstractAgentFactory;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Exception\Worker\AgentCreationFailedException;

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
     * @param SignalRouter $signalRouter Signal router instance
     * @return AgentInterface Agent instance
     * @throws AgentCreationFailedException If agent type is not supported or agentIndex is invalid
     */
    public static function createAgent(string $agentType, ?string $agentIndex, SignalRouter $signalRouter): AgentInterface
    {
        return match ($agentType) {
            AgentType::CHAT => new ChatAgent($signalRouter),
            AgentType::SESSION => self::createUserAgent($agentIndex, $signalRouter),
            default => throw new AgentCreationFailedException($agentType, $agentIndex),
        };
    }

    /**
     * Create UserAgent instance
     *
     * @param ?string $agentIndex User ID
     * @param SignalRouter $signalRouter Signal router instance
     * @return AgentInterface UserAgent instance
     * @throws AgentCreationFailedException If agentIndex is null or empty
     */
    private static function createUserAgent(?string $agentIndex, SignalRouter $signalRouter): AgentInterface
    {
        if ($agentIndex === null || $agentIndex === '') {
            throw new AgentCreationFailedException('user', $agentIndex);
        }
        return new UserAgent($agentIndex, $signalRouter);
    }
}
