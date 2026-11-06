<?php

declare(strict_types=1);

namespace Demo\Chat\Agents\Hilos;

use Hilos\Core\Agent\Hilos\AbstractHilosAnalyticsAgent;

/**
 * DemoHilosAnalyticsAgent - Concrete Hilos analytics agent for chat demo.
 *
 * Handles Hilos analytics page (visit statistics) in the demo project.
 */
final class DemoHilosAnalyticsAgent extends AbstractHilosAnalyticsAgent
{
    /** Section reads arrive in frames; journal maintenance runs in separate agents. */
    protected const MAX_IDLE_SLEEP_SECONDS = 600;
}
