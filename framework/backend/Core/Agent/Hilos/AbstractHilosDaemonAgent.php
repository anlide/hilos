<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Hilos;

use Hilos\Constants\HilosAgentType;

/** Project-bound page agent of the Daemon section. */
abstract class AbstractHilosDaemonAgent extends AbstractHilosAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_DAEMON;
}
