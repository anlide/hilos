<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Agent\Daemon\Hilos;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/**
 * Daemon proxy for DemoHilosAnalyticsAgent.
 */
final class DemoHilosAnalyticsAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_ANALYTICS;

    /**
     * Hilos analytics agent requires monopolistic worker.
     *
     * @return bool True because section SQL reads run in a dedicated worker
     */
    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }
}
