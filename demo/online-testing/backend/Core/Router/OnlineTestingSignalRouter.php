<?php

declare(strict_types=1);

namespace Demo\OnlineTesting\Core\Router;

use Demo\OnlineTesting\Constants\AgentType;
use Demo\OnlineTesting\Hilos;
use Hilos\Core\Router\SignalRouter;

/**
 * OnlineTestingSignalRouter - Signal router for the online-testing demo.
 *
 * Declares demo service-signal defaults. Page subscription, page actions, and
 * page-owned signals are resolved by framework SignalRouter from project
 * topology.
 */
final class OnlineTestingSignalRouter extends SignalRouter
{
    /**
     * Returns the online-testing project facade for topology registry reads.
     *
     * @return class-string<Hilos> OnlineTesting project facade class
     */
    protected function hilosClass(): string
    {
        return Hilos::class;
    }

    /**
     * Returns online-testing agents started on DAEMON/SYSTEM bootstrap signals.
     *
     * @return list<string> Agent type identifiers
     */
    protected function getDefaultSystemBootstrapAgentTypes(): array
    {
        return [
            AgentType::ONLINE_TESTING,
        ];
    }

    /**
     * Returns the online-testing owner for WebSocket lifecycle service signals.
     *
     * @return ?string Fallback agent type
     */
    protected function getDefaultWebSocketLifecycleAgentType(): ?string
    {
        return AgentType::ONLINE_TESTING;
    }
}
