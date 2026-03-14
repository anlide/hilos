<?php

declare(strict_types=1);

namespace Demo\Chat\Agents\Hilos;

use Hilos\Core\Agent\Hilos\AbstractHilosAnalyticsAgent;

/**
 * DemoHilosAnalyticsAgent - Concrete Hilos analytics agent for chat demo.
 *
 * Handles Hilos analytics page (visit statistics) in the demo project.
 */
class DemoHilosAnalyticsAgent extends AbstractHilosAnalyticsAgent
{
    /**
     * Periodic tick handler (no-op for Hilos analytics agent).
     */
    public function onTick(): void
    {
    }
}
