<?php

declare(strict_types=1);

namespace Demo\Polls\Core\Router;

use Demo\Polls\Constants\AgentType;
use Demo\Polls\Hilos;
use Hilos\Core\Router\SignalRouter;

/**
 * PollsSignalRouter - Signal router for the polls demo.
 *
 * Declares demo service-signal defaults. Page subscription, page actions, and
 * page-owned signals are resolved by framework SignalRouter from project
 * topology.
 */
final class PollsSignalRouter extends SignalRouter
{
    /**
     * Returns the polls project facade for topology registry reads.
     *
     * @return class-string<Hilos> Polls project facade class
     */
    protected function hilosClass(): string
    {
        return Hilos::class;
    }

    /**
     * Returns polls agents started on DAEMON/SYSTEM bootstrap signals.
     *
     * @return list<string> Agent type identifiers
     */
    protected function getDefaultSystemBootstrapAgentTypes(): array
    {
        return [
            AgentType::POLLS,
            AgentType::HILOS_LOGS,
        ];
    }

    /**
     * Returns the polls owner for WebSocket lifecycle service signals.
     *
     * @return ?string Fallback agent type
     */
    protected function getDefaultWebSocketLifecycleAgentType(): ?string
    {
        return AgentType::POLLS;
    }
}
