<?php

declare(strict_types=1);

namespace Demo\Cluster\Core\Agent\Daemon;

use Demo\Cluster\Constants\AgentType;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/**
 * Daemon proxy for the per-node runtime set probe (HIL-1116).
 *
 * A stub, for the reasons {@see DbProbeAgentDaemon} is one:
 * - it names no required capability, because {@see AgentScope::NODE} puts a replica on every
 *   node, coordination and data plane alike, and each node's replica owns that node's set;
 * - it asks for no monopolistic worker: the work is one runtime row written, over in
 *   microseconds, and it holds nothing exclusive between commands.
 */
final class RtSetProbeAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = AgentType::RT_SET_PROBE;

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
