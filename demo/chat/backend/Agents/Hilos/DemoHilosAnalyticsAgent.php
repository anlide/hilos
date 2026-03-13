<?php

declare(strict_types=1);

namespace Demo\Chat\Agents\Hilos;

use Hilos\Core\Agent\Hilos\AbstractHilosAnalyticsAgent;

/**
 * DemoHilosAnalyticsAgent - Concrete Hilos analytics agent for chat demo
 *
 * Handles Hilos analytics page (visit statistics) in the demo project.
 */
class DemoHilosAnalyticsAgent extends AbstractHilosAnalyticsAgent
{
    public function onTick(): void
    {
    }
}
