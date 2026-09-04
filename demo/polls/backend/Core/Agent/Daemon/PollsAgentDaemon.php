<?php

declare(strict_types=1);

namespace Demo\Polls\Core\Agent\Daemon;

use Demo\Polls\Constants\AgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/**
 * Daemon proxy for the poll agent.
 */
final class PollsAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = AgentType::POLLS;

    /**
     * Polls agent will own the shared polls DB data, so it must be single-owner.
     *
     * @return bool True because the poll list is shared state with one writer
     */
    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }
}
