<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Agent;

use Demo\Chat\Agents\BotAgent;
use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Agents\ChatContextAnalyzerAgent;
use Demo\Chat\Agents\ModeratorAgent;
use Demo\Chat\Constants\AgentType;
use Demo\Chat\Guardian\Agents\ChatSituationGuardianAgent;
use Demo\Chat\Guardian\Agents\GuardiansOpsAgent;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\HilosAgentWorkerFactory;

/**
 * ChatAgentFactory - Factory for creating chat-specific agents in worker processes.
 *
 * Creates ChatAgent, BotAgent and ModeratorAgent instances based on agent type.
 * Extends HilosAgentWorkerFactory to delegate unknown types to framework.
 */
class ChatAgentWorkerFactory extends HilosAgentWorkerFactory
{
    /**
     * Create agent instance based on type
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index (optional)
     * @return AgentInterface Agent instance
     */
    public static function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        return match ($agentType) {
            AgentType::CHAT => new ChatAgent(),
            AgentType::CHAT_CONTEXT_ANALYZER => new ChatContextAnalyzerAgent(),
            AgentType::GUARDIAN_OPS => new GuardiansOpsAgent(),
            AgentType::CHAT_SITUATION_GUARDIAN => new ChatSituationGuardianAgent(),
            AgentType::BOT => new BotAgent(
                $agentIndex ?? throw new \RuntimeException('BotAgent requires agentIndex (bot id)'),
            ),
            AgentType::MODERATOR => new ModeratorAgent(),
            default => parent::createAgent($agentType, $agentIndex),
        };
    }
}
