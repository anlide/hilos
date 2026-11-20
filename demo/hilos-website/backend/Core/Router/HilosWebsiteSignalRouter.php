<?php

declare(strict_types=1);

namespace Demo\HilosWebsite\Core\Router;

use Demo\HilosWebsite\Constants\AgentType;
use Demo\HilosWebsite\Hilos;
use Hilos\Core\Router\SignalRouter;

/**
 * HilosWebsiteSignalRouter - Signal router for the hilos-website demo.
 *
 * Declares demo service-signal defaults. Page subscription, page actions, and
 * page-owned signals are resolved by framework SignalRouter from project
 * topology.
 */
final class HilosWebsiteSignalRouter extends SignalRouter
{
    /**
     * Returns the hilos-website project facade for topology registry reads.
     *
     * @return class-string<Hilos> HilosWebsite project facade class
     */
    protected function hilosClass(): string
    {
        return Hilos::class;
    }

    /**
     * Returns hilos-website agents started on DAEMON/SYSTEM bootstrap signals.
     *
     * @return list<string> Agent type identifiers
     */
    protected function getDefaultSystemBootstrapAgentTypes(): array
    {
        return [
            AgentType::WEBSITE,
            AgentType::HILOS_INDEX,
        ];
    }

    /**
     * Returns the hilos-website owner for WebSocket lifecycle service signals.
     *
     * @return ?string Fallback agent type
     */
    protected function getDefaultWebSocketLifecycleAgentType(): ?string
    {
        return AgentType::WEBSITE;
    }
}
