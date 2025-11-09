<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Core\Agent\Daemon;

use Demo\WebSocketTest\Constants\AgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemonFactory;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Exception\Worker\AgentDaemonCreationFailedException;

/**
 * ChatAgentDaemonFactory - Factory for creating chat-specific agent daemon proxies
 *
 * Creates ChatAgentDaemon and UserAgentDaemon instances based on agent type.
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
            AgentType::USER => self::createUserAgentDaemon($agentIndex),
            default => throw new AgentDaemonCreationFailedException($agentType, $agentIndex),
        };
    }

    /**
     * Create UserAgentDaemon instance
     *
     * @param ?string $agentIndex User ID
     * @return AgentDaemonInterface UserAgentDaemon instance
     * @throws AgentDaemonCreationFailedException If agentIndex is null or empty
     */
    private static function createUserAgentDaemon(?string $agentIndex): AgentDaemonInterface
    {
        if ($agentIndex === null || $agentIndex === '') {
            throw new AgentDaemonCreationFailedException('user', $agentIndex);
        }
        return new UserAgentDaemon($agentIndex);
    }
}
