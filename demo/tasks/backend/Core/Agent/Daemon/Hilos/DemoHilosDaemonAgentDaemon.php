<?php

declare(strict_types=1);

namespace Demo\Tasks\Core\Agent\Daemon\Hilos;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/** Regular-worker proxy for the tasks demo's Daemon section pages. */
final class DemoHilosDaemonAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_DAEMON;

    /** @return bool False: this page agent shares a regular worker */
    public function requiresMonopolisticProcess(): bool
    {
        return false;
    }
}
