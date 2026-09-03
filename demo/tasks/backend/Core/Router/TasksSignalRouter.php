<?php

declare(strict_types=1);

namespace Demo\Tasks\Core\Router;

use Demo\Tasks\Constants\AgentType;
use Demo\Tasks\Hilos;
use Hilos\Core\Router\SignalRouter;

/**
 * TasksSignalRouter - Signal router for the tasks demo.
 *
 * Declares demo service-signal defaults. Page subscription, page actions, and
 * page-owned signals are resolved by framework SignalRouter from project
 * topology.
 */
final class TasksSignalRouter extends SignalRouter
{
    /**
     * Returns the tasks project facade for topology registry reads.
     *
     * @return class-string<Hilos> Tasks project facade class
     */
    protected function hilosClass(): string
    {
        return Hilos::class;
    }

    /**
     * Returns tasks agents started on DAEMON/SYSTEM bootstrap signals.
     *
     * @return list<string> Agent type identifiers
     */
    protected function getDefaultSystemBootstrapAgentTypes(): array
    {
        return [
            AgentType::TASKS,
            AgentType::HILOS_LOGS,
        ];
    }

    /**
     * Returns the tasks owner for WebSocket lifecycle service signals.
     *
     * @return ?string Fallback agent type
     */
    protected function getDefaultWebSocketLifecycleAgentType(): ?string
    {
        return AgentType::TASKS;
    }
}
