<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Agent\Daemon\Hilos;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/**
 * Daemon proxy for DemoHilosSuperfanAgent.
 */
final class DemoHilosSuperfanAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_SUPERFANS;

    /**
     * Hilos superfans agent requires monopolistic worker.
     *
     * @return bool True because superfan runs long AI inspections in one owner
     */
    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }
}
