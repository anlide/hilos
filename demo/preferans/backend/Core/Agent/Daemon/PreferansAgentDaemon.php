<?php

declare(strict_types=1);

namespace Demo\Preferans\Core\Agent\Daemon;

use Demo\Preferans\Constants\AgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/**
 * Daemon proxy for the preferans agent.
 */
final class PreferansAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = AgentType::PREFERANS;

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
