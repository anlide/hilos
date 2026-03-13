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
    public function getType(): string
    {
        return HilosAgentType::HILOS_ANALYTICS;
    }

    public function getIndex(): ?string
    {
        return null;
    }

    public function onStop(): void
    {
    }
}
