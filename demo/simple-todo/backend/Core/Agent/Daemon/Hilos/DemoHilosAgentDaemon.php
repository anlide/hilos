<?php

declare(strict_types=1);

namespace Demo\SimpleTodo\Core\Agent\Daemon\Hilos;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/**
 * Daemon proxy for DemoHilosAgent (the Hilos dashboard index agent).
 */
final class DemoHilosAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_INDEX;

    /**
     * Hilos index agent requires a monopolistic worker.
     *
     * @return bool True because the Hilos admin pages share one worker owner
     */
    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }
}
