<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Agent\Daemon\Hilos;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/**
 * Daemon proxy for DemoHilosAgent (index/dashboard/settings/i18n).
 */
final class DemoHilosAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_INDEX;

    /**
     * Hilos index agent requires monopolistic worker.
     *
     * @return bool True because Hilos admin pages share one worker owner
     */
    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }
}
