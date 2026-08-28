<?php

declare(strict_types=1);

namespace Demo\Cluster\Core\Agent\Daemon;

use Demo\Cluster\Constants\AgentType;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/**
 * Daemon proxy for the per-node database probe (HIL-712).
 *
 * A stub, and both of the things it does not say are the point:
 * - it names no required capability, unlike {@see WorkerAgentDaemon}, because the probe is
 *   not placed on a node the leader picked - {@see AgentScope::NODE} puts a replica on every
 *   node, coordination and data plane alike, and the whole scenario turns on the reader being
 *   a different node from the writer;
 * - it asks for no monopolistic worker: the work is one row read or one row written, over in
 *   microseconds, and it holds no file and no exclusive right between commands.
 */
final class DbProbeAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = AgentType::DB_PROBE;

    /**
     * The probe owns nothing exclusive, so it runs on a regular worker.
     *
     * @return bool False: not a monopolistic agent
     */
    public function requiresMonopolisticProcess(): bool
    {
        return false;
    }
}
