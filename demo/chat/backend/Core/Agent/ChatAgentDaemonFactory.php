<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Agent;

use Demo\Chat\Constants\AgentType;
use Demo\Chat\Core\Agent\Daemon\BotAgentDaemon;
use Demo\Chat\Core\Agent\Daemon\ChatAgentDaemon;
use Demo\Chat\Core\Agent\Daemon\ModeratorAgentDaemon;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemonFactory;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Exception\Worker\AgentDaemonCreationFailedException;

/**
 * ChatAgentDaemonFactory - Factory for creating chat-specific agent daemon proxies
 *
 * Creates ChatAgentDaemon, BotAgentDaemon and ModeratorAgentDaemon instances based on agent type.
 */
class ChatAgentDaemonFactory extends AbstractAgentDaemonFactory
{
    /**
     * Create agent daemon instance based on type
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index (optional)
     * @return AgentDaemonInterface Agent daemon instance
     * @throws AgentDaemonCreationFailedException If agent type is not supported or agentIndex is invalid
     */
    public static function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        return match ($agentType) {
            AgentType::CHAT => new ChatAgentDaemon(),
            AgentType::BOT => new BotAgentDaemon(),
            AgentType::MODERATOR => new ModeratorAgentDaemon(),
            default => throw new AgentDaemonCreationFailedException($agentType, $agentIndex),
        };
    }
}
