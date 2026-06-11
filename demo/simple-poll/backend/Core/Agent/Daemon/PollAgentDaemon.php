<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Core\Agent\Daemon;

use Demo\SimplePoll\Constants\AgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/**
 * Daemon proxy for the poll agent.
 */
final class PollAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = AgentType::POLL;

    /**
     * Poll agent will own the shared polls DB data, so it must be single-owner.
     *
     * @return bool True because the poll list is shared state with one writer
     */
    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }
}
