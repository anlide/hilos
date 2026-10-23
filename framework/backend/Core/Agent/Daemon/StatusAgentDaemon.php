<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Daemon;

use Hilos\Constants\HilosAgentType;

/** Regular-worker proxy for the cluster Status owner. */
final class StatusAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_STATUS;

    /** @return bool Status uses a regular worker */
    public function requiresMonopolisticProcess(): bool
    {
        return false;
    }
}
