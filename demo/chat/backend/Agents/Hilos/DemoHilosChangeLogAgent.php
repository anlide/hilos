<?php

declare(strict_types=1);

namespace Demo\Chat\Agents\Hilos;

use Hilos\Core\Agent\Hilos\AbstractHilosChangeLogAgent;

/** Chat's Change Log section agent. */
final class DemoHilosChangeLogAgent extends AbstractHilosChangeLogAgent
{
    /** Reads and writer attribution finish in their frame handlers. */
    protected const MAX_IDLE_SLEEP_SECONDS = 600;
}
