<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Agent\Daemon\Hilos;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Utils\Logger;

/**
 * DemoHilosAnalyticsAgentDaemon - Daemon proxy for DemoHilosAnalyticsAgent.
 *
 * Handles routing between WebSocket clients and DemoHilosAnalyticsAgent in worker.
 */
class DemoHilosAnalyticsAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_ANALYTICS;

    /**
     * Creates daemon proxy for DemoHilosAnalyticsAgent.
     */
    public function __construct()
    {
        Logger::debug("DemoHilosAnalyticsAgentDaemon created [type=" . self::AGENT_TYPE . "]");
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
