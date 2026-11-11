<?php

declare(strict_types=1);

namespace Demo\SolarSystemModel\Core\Router;

use Demo\SolarSystemModel\Constants\AgentType;
use Demo\SolarSystemModel\Hilos;
use Hilos\Core\Router\SignalRouter;

/**
 * SolarSystemModelSignalRouter - Signal router for the Solar System Model demo.
 *
 * Declares demo service-signal defaults. Page subscription, page actions, and
 * page-owned signals are resolved by framework SignalRouter from project
 * topology.
 */
final class SolarSystemModelSignalRouter extends SignalRouter
{
    /**
     * Returns the Solar System Model project facade for topology registry reads.
     *
     * @return class-string<Hilos> SolarSystemModel project facade class
     */
    protected function hilosClass(): string
    {
        return Hilos::class;
    }

    /**
     * Returns Solar System Model agents started on DAEMON/SYSTEM bootstrap signals.
     *
     * @return list<string> Agent type identifiers
     */
    protected function getDefaultSystemBootstrapAgentTypes(): array
    {
        return [
            AgentType::SOLAR_SYSTEM_MODEL,
            AgentType::HILOS_INDEX,
        ];
    }

    /**
     * Returns the Solar System Model owner for WebSocket lifecycle service signals.
     *
     * @return ?string Fallback agent type
     */
    protected function getDefaultWebSocketLifecycleAgentType(): ?string
    {
        return AgentType::SOLAR_SYSTEM_MODEL;
    }
}
