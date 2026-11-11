<?php

declare(strict_types=1);

namespace Demo\SolarSystemModel\Core\Agent\Daemon;

use Demo\SolarSystemModel\Constants\AgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/**
 * Daemon proxy for the Solar System Model agent.
 */
final class SolarSystemModelAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = AgentType::SOLAR_SYSTEM_MODEL;

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
