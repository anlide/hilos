<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Hilos;

use Hilos\Constants\HilosAgentType;

/**
 * AbstractHilosAnalyticsAgent - Abstract agent for Hilos analytics page (visit statistics)
 *
 * Projects must extend this class to provide a concrete agent for the Hilos analytics page.
 * If not extended, the analytics page will not function.
 */
abstract class AbstractHilosAnalyticsAgent extends AbstractHilosAgent
{
    /**
     * Return agent type identifier.
     *
     * @return string Agent type
     */
    public function getType(): string
    {
        return HilosAgentType::HILOS_ANALYTICS;
    }

    /**
     * Get agent index (optional identifier for multi-instance agents).
     *
     * @return ?string Agent index or null
     */
    public function getIndex(): ?string
    {
        return null;
    }

    /**
     * Hook called when agent is stopping.
     */
    public function onStop(): void
    {
    }
}
