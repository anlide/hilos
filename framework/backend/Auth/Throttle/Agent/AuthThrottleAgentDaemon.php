<?php

declare(strict_types=1);

namespace Hilos\Auth\Throttle\Agent;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/**
 * Daemon proxy for the per-node auth throttle agent (HIL-420).
 *
 * Opts out of cluster leadership and of a monopolistic worker, and for a related
 * reason: the attempt counters are node-local. Runtime sync reaches the workers of
 * one node and no further, so an agent pinned to the leader would leave every other
 * node counting nothing. What must be global is not the count but the block, and
 * that is global already - it is a row in the shared database, which every node's
 * agent reads on start.
 */
final class AuthThrottleAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_AUTH_THROTTLE;

    /**
     * The throttle agent holds no cross-node monopoly, so it runs on a regular worker.
     *
     * @return bool False: not a monopolistic agent
     */
    public function requiresMonopolisticProcess(): bool
    {
        return false;
    }
}
