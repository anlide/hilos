<?php

declare(strict_types=1);

namespace Demo\ConcertTickets\Core\Router;

use Demo\ConcertTickets\Constants\AgentType;
use Demo\ConcertTickets\Hilos;
use Hilos\Core\Router\SignalRouter;

/**
 * ConcertTicketsSignalRouter - Signal router for the concert-tickets demo.
 *
 * Declares demo service-signal defaults. Page subscription, page actions, and
 * page-owned signals are resolved by framework SignalRouter from project
 * topology.
 */
final class ConcertTicketsSignalRouter extends SignalRouter
{
    /**
     * Returns the concert-tickets project facade for topology registry reads.
     *
     * @return class-string<Hilos> Concert Tickets project facade class
     */
    protected function hilosClass(): string
    {
        return Hilos::class;
    }

    /**
     * Returns concert-tickets agents started on DAEMON/SYSTEM bootstrap signals.
     *
     * @return list<string> Agent type identifiers
     */
    protected function getDefaultSystemBootstrapAgentTypes(): array
    {
        return [
            AgentType::CONCERT_TICKETS,
            AgentType::HILOS_INDEX,
            AgentType::HILOS_LOGS,
        ];
    }

    /**
     * Returns the concert-tickets owner for WebSocket lifecycle service signals.
     *
     * @return ?string Fallback agent type
     */
    protected function getDefaultWebSocketLifecycleAgentType(): ?string
    {
        return AgentType::CONCERT_TICKETS;
    }
}
