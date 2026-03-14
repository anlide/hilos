<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Agent\Daemon;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Utils\Logger;

/**
 * DemoHilosAnalyticsAgentDaemon - Daemon proxy for DemoHilosAnalyticsAgent
 *
 * Handles routing between WebSocket clients and DemoHilosAnalyticsAgent in worker.
 */
class DemoHilosAnalyticsAgentDaemon extends AbstractAgentDaemon
{
    /**
     * Creates daemon proxy for DemoHilosAnalyticsAgent.
     */
    public function __construct()
    {
        Logger::debug("DemoHilosAnalyticsAgentDaemon created [type=" . HilosAgentType::HILOS_ANALYTICS . "]");
    }

    /**
     * Get agent type identifier.
     *
     * @return string Agent type
     */
    public function getType(): string
    {
        return HilosAgentType::HILOS_ANALYTICS;
    }

    /**
     * Get agent index (null for global Hilos analytics agent).
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
     * @return bool True (Hilos analytics agent requires monopolistic worker)
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
