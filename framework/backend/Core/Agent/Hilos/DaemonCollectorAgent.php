<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Hilos;

use Hilos\Constants\HilosAgentType;

/** Cluster Daemon section collector; later leaves supply its frame handling. */
final class DaemonCollectorAgent extends AbstractHilosAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_DAEMON_COLLECTOR;
}
