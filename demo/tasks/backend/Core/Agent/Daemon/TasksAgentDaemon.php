<?php

declare(strict_types=1);

namespace Demo\Tasks\Core\Agent\Daemon;

use Demo\Tasks\Constants\AgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/**
 * Daemon proxy for the tasks agent.
 */
final class TasksAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = AgentType::TASKS;

    /**
     * Tasks agent will own the shared tasks DB data, so it must be single-owner.
     *
     * @return bool True because the tasks list is shared state with one writer
     */
    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }
}
