<?php

declare(strict_types=1);

namespace Demo\Tasks\Core\Agent\Daemon\Hilos;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/**
 * Daemon proxy for DemoHilosLogsAgent.
 */
final class DemoHilosLogsAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_LOGS;

    /**
     * Hilos logs agent requires monopolistic worker.
     *
     * @return bool True because log access follows other Hilos admin agents
     */
    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }
}
