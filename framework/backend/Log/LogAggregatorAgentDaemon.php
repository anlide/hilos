<?php

declare(strict_types=1);

namespace Hilos\Log;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/**
 * Daemon proxy for the cluster log aggregator (HIL-754).
 *
 * A plain stub: it neither forwards messages to a user nor carries an index. Where the agent runs
 * is declared in the registry — one instance cluster-wide, on the node the placement policy picks.
 */
final class LogAggregatorAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_LOG_AGGREGATOR;

    /**
     * The aggregator does no blocking work, so it shares a regular worker.
     *
     * Its reason is not the rotation agent's. Files are touched only by the node that owns them
     * ({@see LogStoreAgent}, which IS monopolistic for exactly that reason); the aggregator only
     * takes frames and adds numbers up. A monopolistic worker holds a single agent, so asking for
     * one here would buy a whole extra process for that. Being the only holder in the cluster is a
     * question of how many instances exist, and the registry's scope answers it — not the kind of
     * process this one lives in.
     *
     * @return bool False: not a monopolistic agent
     */
    public function requiresMonopolisticProcess(): bool
    {
        return false;
    }
}
