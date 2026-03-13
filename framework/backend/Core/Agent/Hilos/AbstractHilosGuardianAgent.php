<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Hilos;

use Hilos\Constants\HilosAgentType;

/**
 * AbstractHilosGuardianAgent - Abstract agent for Hilos guardian page (project validation robots)
 *
 * Projects must extend this class to provide a concrete agent for the Hilos guardian page.
 * If not extended, the guardian page will not function.
 */
abstract class AbstractHilosGuardianAgent extends AbstractHilosAgent
{
    /**
     * Return agent type identifier.
     *
     * @return string Agent type
     */
    public function getType(): string
    {
        return HilosAgentType::HILOS_GUARDIAN;
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
