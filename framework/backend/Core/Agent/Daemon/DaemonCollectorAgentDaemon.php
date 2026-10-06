<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Daemon;

use Hilos\Constants\HilosAgentType;

/** Regular-worker proxy for the cluster Daemon section collector. */
final class DaemonCollectorAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_DAEMON_COLLECTOR;

    /** @return bool False: this agent shares a regular worker */
    public function requiresMonopolisticProcess(): bool
    {
        return false;
    }
}
