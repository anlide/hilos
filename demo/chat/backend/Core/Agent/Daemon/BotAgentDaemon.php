<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Agent\Daemon;

use Demo\Chat\Constants\AgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\Agent\Exception\AgentIndexRequiredException;

/**
 * BotAgentDaemon - Daemon proxy for BotAgent.
 *
 * Simple proxy class for bot agent on daemon side.
 * One daemon per bot (agentIndex = bot.id).
 */
final class BotAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = AgentType::BOT;

    /**
     * Creates daemon proxy for BotAgent with given bot id as index.
     *
     * @param string $agentIndex Bot id (non-empty)
     * @throws AgentIndexRequiredException When agentIndex is empty
     */
    public function __construct(string $agentIndex)
    {
        if ($agentIndex === '') {
            throw new AgentIndexRequiredException('BotAgentDaemon requires non-empty agentIndex (bot id)');
        }
        $this->agentIndex = $agentIndex;
    }

    /**
     * Check if agent requires monopolistic worker process.
     *
     * Bot agent does not require monopolistic worker.
     *
     * @return bool False (bot agent does not require monopolistic worker)
     */
    public function requiresMonopolisticProcess(): bool
    {
        return false;
    }
}
