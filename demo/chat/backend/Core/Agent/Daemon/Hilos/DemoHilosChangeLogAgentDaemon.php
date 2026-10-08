<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Agent\Daemon\Hilos;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/** Daemon proxy for the Change Log section reader. */
final class DemoHilosChangeLogAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_CHANGE_LOG;

    /**
     * Section SQL reads of the journal run in a dedicated worker.
     *
     * @return bool True for a monopolistic process
     */
    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }
}
