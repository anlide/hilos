<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Core\Router;

use Demo\SimplePoll\Constants\AgentType;
use Demo\SimplePoll\Hilos;
use Hilos\Core\Router\SignalRouter;

/**
 * PollSignalRouter - Signal router for the simple-poll demo.
 *
 * Declares demo service-signal defaults. Page subscription, page actions, and
 * page-owned signals are resolved by framework SignalRouter from project
 * topology.
 */
final class PollSignalRouter extends SignalRouter
{
    /**
     * Returns the simple-poll project facade for topology registry reads.
     *
     * @return class-string<Hilos> Simple-poll project facade class
     */
    protected function hilosClass(): string
    {
        return Hilos::class;
    }

    /**
     * Returns poll agents started on DAEMON/SYSTEM bootstrap signals.
     *
     * @return list<string> Agent type identifiers
     */
    protected function getDefaultSystemBootstrapAgentTypes(): array
    {
        return [
            AgentType::POLL,
            AgentType::HILOS_LOGS,
        ];
    }

    /**
     * Returns the poll owner for WebSocket lifecycle service signals.
     *
     * @return ?string Fallback agent type
     */
    protected function getDefaultWebSocketLifecycleAgentType(): ?string
    {
        return AgentType::POLL;
    }
}
