<?php

declare(strict_types=1);

namespace Demo\SimpleTodo\Agents;

use Demo\SimpleTodo\Constants\AgentType;
use Hilos\Core\Agent\AbstractAgent;

/**
 * Monopolistic todo worker that owns the main page subscription and the
 * WebSocket lifecycle signals.
 *
 * Declares no truth sources yet: the todos table and its DB ownership arrive
 * with the first data-on-screen rewrite step.
 */
final class TodoAgent extends AbstractAgent
{
    public const string AGENT_TYPE = AgentType::TODO;

    /**
     * No durable state to clean up; WorkerManager unregisters the agent itself.
     */
    public function onStop(): void
    {
    }
}
