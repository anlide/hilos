<?php

declare(strict_types=1);

namespace Demo\SimpleTodo\Core\Router;

use Demo\SimpleTodo\Constants\AgentType;
use Demo\SimpleTodo\Hilos;
use Hilos\Core\Router\SignalRouter;

/**
 * TodoSignalRouter - Signal router for the simple-todo demo.
 *
 * Declares demo service-signal defaults. Page subscription, page actions, and
 * page-owned signals are resolved by framework SignalRouter from project
 * topology.
 */
final class TodoSignalRouter extends SignalRouter
{
    /**
     * Returns the simple-todo project facade for topology registry reads.
     *
     * @return class-string<Hilos> Simple-todo project facade class
     */
    protected function hilosClass(): string
    {
        return Hilos::class;
    }

    /**
     * Returns todo agents started on DAEMON/SYSTEM bootstrap signals.
     *
     * @return list<string> Agent type identifiers
     */
    protected function getDefaultSystemBootstrapAgentTypes(): array
    {
        return [
            AgentType::TODO,
        ];
    }

    /**
     * Returns the todo owner for WebSocket lifecycle service signals.
     *
     * @return ?string Fallback agent type
     */
    protected function getDefaultWebSocketLifecycleAgentType(): ?string
    {
        return AgentType::TODO;
    }
}
