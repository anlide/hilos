<?php

declare(strict_types=1);

namespace Demo\Pmd\Core\Router;

use Demo\Pmd\Constants\AgentType;
use Demo\Pmd\Hilos;
use Hilos\Core\Router\SignalRouter;

/**
 * PmdSignalRouter - Signal router for the PMD demo.
 *
 * Declares demo service-signal defaults. Page subscription, page actions, and
 * page-owned signals are resolved by framework SignalRouter from project
 * topology.
 */
final class PmdSignalRouter extends SignalRouter
{
    /**
     * Returns the PMD project facade for topology registry reads.
     *
     * @return class-string<Hilos> Pmd project facade class
     */
    protected function hilosClass(): string
    {
        return Hilos::class;
    }

    /**
     * Returns PMD agents started on DAEMON/SYSTEM bootstrap signals.
     *
     * @return list<string> Agent type identifiers
     */
    protected function getDefaultSystemBootstrapAgentTypes(): array
    {
        return [
            AgentType::PMD,
            AgentType::HILOS_INDEX,
        ];
    }

    /**
     * Returns the pmd owner for WebSocket lifecycle service signals.
     *
     * @return ?string Fallback agent type
     */
    protected function getDefaultWebSocketLifecycleAgentType(): ?string
    {
        return AgentType::PMD;
    }
}
