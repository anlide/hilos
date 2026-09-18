<?php

declare(strict_types=1);

namespace Hilos\Socket\Server;

use Hilos\Core\Daemon\ParkedAgentSignal;

/**
 * One monopolistic agent the master is raising a worker for (HIL-998).
 *
 * The pool of monopolistic workers grows on demand: an agent that needs a process of its own and
 * finds none free orders one, and waits here until a free monopolistic worker exists or its
 * deadline passes. The wait is the agent's, not a frame's - frames addressed to it meanwhile are
 * held by the master as {@see ParkedAgentSignal}, the same hold every agent whose start is under
 * way gets, and that hold outlasts this one by a second so a frame meets a verdict rather than an
 * empty wait.
 */
final readonly class AwaitingWorkerAgent
{
    /**
     * @param string $agentId Agent that waits for a worker
     * @param string $agentType Agent type, for the start that seats it
     * @param ?string $agentIndex Agent index, for the same start; null for a singleton
     * @param bool $placedByLeader Whether the start carried the placement sanction, which the start that
     *     seats it must carry again - a policy-placed agent is refused without it off the leader
     * @param float $deadline Unix seconds after which the agent gives up and its start is refused
     */
    public function __construct(
        public string $agentId,
        public string $agentType,
        public ?string $agentIndex,
        public bool $placedByLeader,
        public float $deadline,
    ) {
    }
}
