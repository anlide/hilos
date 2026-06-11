<?php

declare(strict_types=1);

namespace Demo\SimpleTodo\Core\Agent\Daemon;

use Demo\SimpleTodo\Constants\AgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/**
 * Daemon proxy for the todo agent.
 */
final class TodoAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = AgentType::TODO;

    /**
     * Todo agent will own the shared todos DB data, so it must be single-owner.
     *
     * @return bool True because the todo list is shared state with one writer
     */
    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }
}
