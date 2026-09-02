<?php

declare(strict_types=1);

namespace Hilos\Log;

use Hilos\Auth\Throttle\Agent\AuthThrottleAgentDaemon;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/**
 * Daemon proxy for the node-local log store agent (HIL-753).
 *
 * Registered per node, so it runs on every node rather than on the leader alone — a log directory
 * is node-local and nobody else can read this one. Unlike its per-node neighbor
 * {@see AuthThrottleAgentDaemon} it does ask for a monopolistic worker. A plain stub otherwise: it
 * neither forwards messages to a user nor carries an index.
 */
final class LogStoreAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_LOG_STORE;

    /**
     * The store agent walks a directory, which is blocking file work, and a node has exactly one
     * reader of its own log directory — so it gets a worker to itself.
     *
     * @return bool True because the log store agent is monopolistic
     */
    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }
}
