<?php

declare(strict_types=1);

namespace Hilos\Socket\Server;

use Hilos\Core\Daemon\ParkedAgentSignal;

/**
 * One agent the master holds until a worker of its type connects (HIL-998, HIL-1671).
 *
 * One queue for both worker types. A monopolistic agent that finds no free worker orders one; a
 * regular agent that finds none while a regular worker this master launched has not connected
 * yet waits for that one. Either waits here until a free worker of its type exists or its
 * deadline passes - {@see WorkerServer::WORKER_CONNECT_WAIT_SECONDS} after it asked. The wait is
 * the agent's, not a frame's - frames addressed to it meanwhile are held by the master as
 * {@see ParkedAgentSignal}, the same hold every agent whose start is under way gets, and that hold
 * keeps no clock of its own: it ends on the verdict this wait gives, seated or refused.
 *
 * Internal to the master process: neither a runtime row nor a wire DTO.
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
     * @param bool $requiresMonopolistic Whether the agent waits for a monopolistic worker rather than a regular one
     */
    public function __construct(
        public string $agentId,
        public string $agentType,
        public ?string $agentIndex,
        public bool $placedByLeader,
        public float $deadline,
        public bool $requiresMonopolistic,
    ) {
    }
}
