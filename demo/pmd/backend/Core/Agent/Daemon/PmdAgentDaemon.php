<?php

declare(strict_types=1);

namespace Demo\Pmd\Core\Agent\Daemon;

use Demo\Pmd\Constants\AgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/**
 * Daemon proxy for the PMD agent.
 */
final class PmdAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = AgentType::PMD;

    /**
     * The app agent owns the connections registry and answers the WebSocket lifecycle, so it
     * must be single-owner.
     *
     * @return bool True because who is on the wire is shared state with one writer
     */
    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }
}
