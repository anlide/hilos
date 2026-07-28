<?php

declare(strict_types=1);

namespace Hilos\Log;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/**
 * Daemon proxy for the per-node log rotation agent (HIL-379).
 *
 * Opts out of cluster leadership so the agent runs on every node rather than only the leader —
 * logs are node-local — and out of a monopolistic worker since it holds no cross-node lock. A
 * plain stub: it neither forwards messages to a user nor carries an index.
 */
final class LogRotationAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_LOG_ROTATION;

    /**
     * The rotation agent holds no cross-node monopoly, so it runs on a regular worker.
     *
     * @return bool False: not a monopolistic agent
     */
    public function requiresMonopolisticProcess(): bool
    {
        return false;
    }

    /**
     * Logs are node-local, so the agent runs on every node rather than pinning to the leader.
     *
     * @return bool False: per-node, not a cluster singleton
     */
    public function requiresClusterLeadership(): bool
    {
        return false;
    }
}
