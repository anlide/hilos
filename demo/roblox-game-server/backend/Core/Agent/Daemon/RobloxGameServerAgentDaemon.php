<?php

declare(strict_types=1);

namespace Demo\RobloxGameServer\Core\Agent\Daemon;

use Demo\RobloxGameServer\Constants\AgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/**
 * Daemon proxy for the roblox-game-server agent.
 */
final class RobloxGameServerAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = AgentType::ROBLOX_GAME_SERVER;

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
