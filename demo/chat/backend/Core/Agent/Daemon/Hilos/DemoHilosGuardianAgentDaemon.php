<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Agent\Daemon\Hilos;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/**
 * Daemon proxy for DemoHilosGuardianAgent.
 */
final class DemoHilosGuardianAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_GUARDIAN;

    /**
     * Hilos guardian agent requires monopolistic worker.
     *
     * @return bool True because guardian runs long AI inspections in one owner
     */
    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }
}
