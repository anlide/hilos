<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Agent\Daemon;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Utils\Logger;

/**
 * DemoHilosGuardianAgentDaemon - Daemon proxy for DemoHilosGuardianAgent
 *
 * Handles routing between WebSocket clients and DemoHilosGuardianAgent in worker.
 */
class DemoHilosGuardianAgentDaemon extends AbstractAgentDaemon
{
    /**
     * Create daemon proxy for DemoHilosGuardianAgent.
     */
    public function __construct()
    {
        Logger::debug("DemoHilosGuardianAgentDaemon created [type=" . HilosAgentType::HILOS_GUARDIAN . "]");
    }

    /**
     * Get agent type identifier.
     *
     * @return string Agent type
     */
    public function getType(): string
    {
        return HilosAgentType::HILOS_GUARDIAN;
    }

    /**
     * Get agent index (null for global Hilos guardian agent).
     *
     * @return ?string Agent index or null
     */
    public function getIndex(): ?string
    {
        return null;
    }

    /**
     * Check if agent requires monopolistic worker process.
     *
     * @return bool True (Hilos guardian agent requires monopolistic worker)
     */
    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }

    /**
     * Handle message from worker agent.
     *
     * @param array $data Message data from worker
     */
    public function handleWorkerMessage(array $data): void
    {
    }

    /**
     * Handle message from external source (WebSocket, HTTP, etc.).
     *
     * @param array $data Message data from external source
     * @return ?array Response data or null
     */
    public function handleExternalMessage(array $data): ?array
    {
        return null;
    }
}
