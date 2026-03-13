<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Agent;

use Demo\Chat\Constants\AgentType;
use Demo\Chat\Core\Agent\Daemon\BotAgentDaemon;
use Demo\Chat\Core\Agent\Daemon\ChatAgentDaemon;
use Demo\Chat\Core\Agent\Daemon\ChatContextAnalyzerAgentDaemon;
use Demo\Chat\Core\Agent\Daemon\ChatSituationGuardianAgentDaemon;
use Demo\Chat\Core\Agent\Daemon\DemoHilosAgentDaemon;
use Demo\Chat\Core\Agent\Daemon\DemoHilosAnalyticsAgentDaemon;
use Demo\Chat\Core\Agent\Daemon\DemoHilosGuardianAgentDaemon;
use Demo\Chat\Core\Agent\Daemon\GuardiansOpsAgentDaemon;
use Demo\Chat\Core\Agent\Daemon\ModeratorAgentDaemon;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\HilosAgentDaemonFactory;

/**
 * ChatAgentDaemonFactory - Factory for creating chat-specific agent daemon proxies
 *
 * Creates chat and Hilos agent daemon instances based on agent type.
 * Extends HilosAgentDaemonFactory to delegate unknown types to framework.
 */
class ChatAgentDaemonFactory extends HilosAgentDaemonFactory
{
    /**
     * Create agent daemon instance based on type.
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index (required for BOT type)
     * @return AgentDaemonInterface Agent daemon instance
     * @throws \RuntimeException If agentIndex is null for BOT type
     */
    public static function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        return match ($agentType) {
            AgentType::CHAT => new ChatAgentDaemon(),
            AgentType::CHAT_CONTEXT_ANALYZER => new ChatContextAnalyzerAgentDaemon(),
            AgentType::GUARDIAN_OPS => new GuardiansOpsAgentDaemon(),
            AgentType::CHAT_SITUATION_GUARDIAN => new ChatSituationGuardianAgentDaemon(),
            AgentType::BOT => new BotAgentDaemon(
                $agentIndex ?? throw new \RuntimeException('BotAgentDaemon requires agentIndex (bot id)'),
            ),
            AgentType::MODERATOR => new ModeratorAgentDaemon(),
            AgentType::HILOS_INDEX => new DemoHilosAgentDaemon(),
            AgentType::HILOS_GUARDIAN => new DemoHilosGuardianAgentDaemon(),
            AgentType::HILOS_ANALYTICS => new DemoHilosAnalyticsAgentDaemon(),
            default => parent::createAgentDaemon($agentType, $agentIndex),
        };
    }
}
