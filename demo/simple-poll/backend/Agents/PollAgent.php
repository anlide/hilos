<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Agents;

use Demo\SimplePoll\Constants\AgentType;
use Hilos\Core\Agent\AbstractAgent;

/**
 * Monopolistic poll worker that owns the main page subscription and the
 * WebSocket lifecycle signals.
 *
 * Declares no truth sources yet: the polls table and its DB ownership arrive
 * with the first data-on-screen rewrite step.
 */
final class PollAgent extends AbstractAgent
{
    public const string AGENT_TYPE = AgentType::POLL;

    /**
     * No durable state to clean up; WorkerManager unregisters the agent itself.
     */
    public function onStop(): void
    {
    }
}
