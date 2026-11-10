<?php

declare(strict_types=1);

namespace Demo\Preferans\Core\Router;

use Demo\Preferans\Constants\AgentType;
use Demo\Preferans\Hilos;
use Hilos\Core\Router\SignalRouter;

/**
 * PreferansSignalRouter - Signal router for the preferans demo.
 *
 * Declares demo service-signal defaults. Page subscription, page actions, and
 * page-owned signals are resolved by framework SignalRouter from project
 * topology.
 */
final class PreferansSignalRouter extends SignalRouter
{
    /**
     * Returns the preferans project facade for topology registry reads.
     *
     * @return class-string<Hilos> Preferans project facade class
     */
    protected function hilosClass(): string
    {
        return Hilos::class;
    }

    /**
     * Returns preferans agents started on DAEMON/SYSTEM bootstrap signals.
     *
     * @return list<string> Agent type identifiers
     */
    protected function getDefaultSystemBootstrapAgentTypes(): array
    {
        return [
            AgentType::PREFERANS,
            AgentType::HILOS_INDEX,
        ];
    }

    /**
     * Returns the preferans owner for WebSocket lifecycle service signals.
     *
     * @return ?string Fallback agent type
     */
    protected function getDefaultWebSocketLifecycleAgentType(): ?string
    {
        return AgentType::PREFERANS;
    }
}
