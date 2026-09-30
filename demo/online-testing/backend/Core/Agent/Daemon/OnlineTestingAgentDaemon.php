<?php

declare(strict_types=1);

namespace Demo\OnlineTesting\Core\Agent\Daemon;

use Demo\OnlineTesting\Constants\AgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/**
 * Daemon proxy for the online-testing agent.
 */
final class OnlineTestingAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = AgentType::ONLINE_TESTING;

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
