<?php

declare(strict_types=1);

namespace Hilos\Cluster\Probe;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\Agent\Exception\AgentIndexRequiredException;

/**
 * Daemon proxy for a member of the cluster probe fleet ({@see FleetProbeAgent}).
 *
 * Its three flags define how the cluster treats it:
 * - NOT monopolistic: fleet members share the node's regular workers, so a node
 *   hosts as many as the leader gives it without pre-forking a process per member;
 * - NOT a cluster-singleton: it is an agent the leader places by policy on any node
 *   advertising the tag, itself last, so it must be startable on a node that is not the
 *   leader;
 * - capability-gated: it runs only on a node advertising the WORKER capability,
 *   which the leader hard-checks before placing it.
 */
final class FleetProbeAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_PROBE_FLEET;

    /**
     * @param string $agentIndex Fleet member index this proxy stands for
     * @throws AgentIndexRequiredException When the fleet member index is empty
     */
    public function __construct(string $agentIndex)
    {
        if ($agentIndex === '') {
            throw new AgentIndexRequiredException('FleetProbeAgentDaemon requires a non-empty agentIndex');
        }

        $this->agentIndex = $agentIndex;
    }

    /**
     * A fleet member owns nothing exclusive, so it shares the node's regular workers.
     *
     * A monopolistic worker holds one agent, and the pool grows one per agent type, never
     * one for an indexed instance (HIL-998). A fleet member carries an index, and after a
     * failover a node must take the whole fleet at once.
     *
     * @return bool False: run in a shared regular worker
     */
    public function requiresMonopolisticProcess(): bool
    {
        return false;
    }

    /**
     * Only a node advertising the WORKER capability may host it.
     *
     * @return list<string> Required capability tags
     */
    public function requiredCapabilities(): array
    {
        return [ClusterProbe::CAPABILITY_WORKER];
    }
}
