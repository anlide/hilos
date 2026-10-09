<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Core\Agent\Daemon\Hilos;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/** Daemon proxy for the Analytics section agent. */
final class DemoHilosAnalyticsAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_ANALYTICS;

    /**
     * Gives bounded Analytics section SQL reads a dedicated worker.
     *
     * @return bool True for the section's dedicated worker
     */
    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }
}
