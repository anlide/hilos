<?php

declare(strict_types=1);

namespace Demo\Chat\Agents\Hilos;

use Hilos\Core\Agent\Hilos\AbstractHilosGuardianAgent;

/**
 * DemoHilosGuardianAgent - Concrete Hilos guardian agent for chat demo
 *
 * Handles Hilos guardian page (project validation robots) in the demo project.
 */
class DemoHilosGuardianAgent extends AbstractHilosGuardianAgent
{
    /**
     * Periodic tick handler (no-op for Hilos guardian agent).
     */
    public function onTick(): void
    {
    }
}
