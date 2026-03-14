<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Agent;

use Demo\Chat\Agents\BotAgent;
use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Agents\ChatContextAnalyzerAgent;
use Demo\Chat\Agents\Hilos\DemoHilosAgent;
use Demo\Chat\Agents\Hilos\DemoHilosAnalyticsAgent;
use Demo\Chat\Agents\Hilos\DemoHilosGuardianAgent;
use Demo\Chat\Agents\ModeratorAgent;
use Demo\Chat\Constants\AgentType;
use Demo\Chat\Guardian\Agents\ChatSituationGuardianAgent;
use Demo\Chat\Guardian\Agents\GuardiansOpsAgent;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\Exception\AgentIndexRequiredException;
use Hilos\Core\Agent\HilosAgentWorkerFactory;

/**
 * ChatAgentWorkerFactory - Factory for creating chat-specific agents in worker processes.
 *
 * Creates chat and Hilos agent instances based on agent type.
 * Extends HilosAgentWorkerFactory to delegate unknown types to framework.
 */
class ChatAgentWorkerFactory extends HilosAgentWorkerFactory
{
    /**
     * Create agent instance based on type.
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index (required for BOT type)
     * @return AgentInterface Agent instance
     * @throws AgentIndexRequiredException When agentIndex is null for BOT type
     */
    public static function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        return match ($agentType) {
            AgentType::CHAT => new ChatAgent(),
            AgentType::CHAT_CONTEXT_ANALYZER => new ChatContextAnalyzerAgent(),
            AgentType::GUARDIAN_OPS => new GuardiansOpsAgent(),
            AgentType::CHAT_SITUATION_GUARDIAN => new ChatSituationGuardianAgent(),
            AgentType::BOT => new BotAgent(
                $agentIndex ?? throw new AgentIndexRequiredException('BotAgent requires agentIndex (bot id)'),
            ),
            AgentType::MODERATOR => new ModeratorAgent(),
            AgentType::HILOS_INDEX => new DemoHilosAgent(),
            AgentType::HILOS_GUARDIAN => new DemoHilosGuardianAgent(),
            AgentType::HILOS_ANALYTICS => new DemoHilosAnalyticsAgent(),
            default => parent::createAgent($agentType, $agentIndex),
        };
    }
}
