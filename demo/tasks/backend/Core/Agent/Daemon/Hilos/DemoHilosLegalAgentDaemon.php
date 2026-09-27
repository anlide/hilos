<?php

declare(strict_types=1);

namespace Demo\Tasks\Core\Agent\Daemon\Hilos;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/** Allocates the legal section's own worker for acceptance-history reads. */
final class DemoHilosLegalAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_LEGAL;

    /** @return bool True because legal tallies read the whole acceptance history */
    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }
}
