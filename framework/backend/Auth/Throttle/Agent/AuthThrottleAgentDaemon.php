<?php

declare(strict_types=1);

namespace Hilos\Auth\Throttle\Agent;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/**
 * Daemon proxy for the auth throttle agent, one for the whole cluster (HIL-420, HIL-1280).
 *
 * The attempt counters are one runtime collection for the whole cluster (HIL-586), so
 * their owner is a cluster singleton: it runs on the node the placement policy picked,
 * and every node's workers ask it there and read its counters from their replica. It
 * is not pinned to the leader, and it holds no monopoly over a worker either - counting
 * is short work that shares a regular worker with anything else.
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
