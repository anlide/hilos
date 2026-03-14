<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Agent\Daemon;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Utils\Logger;

/**
 * DemoHilosAgentDaemon - Daemon proxy for DemoHilosAgent (index/dashboard/settings/i18n)
 *
 * Handles routing between WebSocket clients and DemoHilosAgent in worker.
 */
class DemoHilosAgentDaemon extends AbstractAgentDaemon
{
    /**
     * Create daemon proxy for DemoHilosAgent.
     */
    public function __construct()
    {
        Logger::debug("DemoHilosAgentDaemon created [type=" . HilosAgentType::HILOS_INDEX . "]");
    }

    /**
     * Get agent type identifier.
     *
     * @return string Agent type
     */
    public function getType(): string
    {
        return HilosAgentType::HILOS_INDEX;
    }

    /**
     * Get agent index (null for global Hilos index agent).
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
     * @return bool True (Hilos index agent requires monopolistic worker)
     */
    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }

    /**
     * Handle message from worker agent.
     *
     * @param array<string, mixed> $data Message data from worker
     */
    public function handleWorkerMessage(array $data): void
    {
    }

    /**
     * Handle message from external source (WebSocket, HTTP, etc.).
     *
     * @param array<string, mixed> $data Message data from external source
     * @return ?array<string, mixed> Response data or null
     */
    public function handleExternalMessage(array $data): ?array
    {
        return null;
    }
}
