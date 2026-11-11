<?php

declare(strict_types=1);

namespace Demo\RobloxGameServer\Core\Router;

use Demo\RobloxGameServer\Constants\AgentType;
use Demo\RobloxGameServer\Hilos;
use Hilos\Core\Router\SignalRouter;

/**
 * RobloxGameServerSignalRouter - Signal router for the roblox-game-server demo.
 *
 * Declares demo service-signal defaults. Page subscription, page actions, and
 * page-owned signals are resolved by framework SignalRouter from project
 * topology.
 */
final class RobloxGameServerSignalRouter extends SignalRouter
{
    /**
     * Returns the roblox-game-server project facade for topology registry reads.
     *
     * @return class-string<Hilos> RobloxGameServer project facade class
     */
    protected function hilosClass(): string
    {
        return Hilos::class;
    }

    /**
     * Returns roblox-game-server agents started on DAEMON/SYSTEM bootstrap signals.
     *
     * @return list<string> Agent type identifiers
     */
    protected function getDefaultSystemBootstrapAgentTypes(): array
    {
        return [
            AgentType::ROBLOX_GAME_SERVER,
            AgentType::HILOS_INDEX,
            AgentType::HILOS_LOGS,
        ];
    }

    /**
     * Returns the roblox-game-server owner for WebSocket lifecycle service signals.
     *
     * @return ?string Fallback agent type
     */
    protected function getDefaultWebSocketLifecycleAgentType(): ?string
    {
        return AgentType::ROBLOX_GAME_SERVER;
    }
}
