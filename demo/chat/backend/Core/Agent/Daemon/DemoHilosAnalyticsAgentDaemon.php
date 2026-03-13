<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Agent\Daemon;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Utils\Logger;

/**
 * DemoHilosAnalyticsAgentDaemon - Daemon proxy for DemoHilosAnalyticsAgent
 */
class DemoHilosAnalyticsAgentDaemon extends AbstractAgentDaemon
{
    public function __construct()
    {
        Logger::debug("DemoHilosAnalyticsAgentDaemon created [type=" . HilosAgentType::HILOS_ANALYTICS . "]");
    }

    public function getType(): string
    {
        return HilosAgentType::HILOS_ANALYTICS;
    }

    public function getIndex(): ?string
    {
        return null;
    }

    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }

    public function handleWorkerMessage(array $data): void
    {
    }

    public function handleExternalMessage(array $data): ?array
    {
        return null;
    }
}
