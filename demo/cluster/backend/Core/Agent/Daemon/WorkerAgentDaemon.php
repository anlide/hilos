<?php

declare(strict_types=1);

namespace Demo\Cluster\Core\Agent\Daemon;

use Demo\Cluster\Constants\AgentType;
use Demo\Cluster\Constants\ClusterCapability;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\Agent\Exception\AgentIndexRequiredException;

/**
 * Daemon proxy for the placeable no-op worker agent.
 *
 * Its three flags define how the cluster treats it:
 * - NOT monopolistic: fleet members share the node's regular workers, so a node
 *   hosts as many as the leader gives it without pre-forking a process per member;
 * - NOT a cluster-singleton: it is a per-node/data-plane agent the leader places
 *   remotely, so it must be startable on a node that is not the leader;
 * - capability-gated: it runs only on a node advertising the WORKER capability,
 *   which the leader hard-checks before placing it.
 */
final class WorkerAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = AgentType::WORKER;

    /**
     * @param string $agentIndex Fleet member index this proxy stands for
     * @throws AgentIndexRequiredException When the fleet member index is empty
     */
    public function __construct(string $agentIndex)
    {
        if ($agentIndex === '') {
            throw new AgentIndexRequiredException('WorkerAgentDaemon requires a non-empty agentIndex');
        }

        $this->agentIndex = $agentIndex;
    }

    /**
     * A fleet member owns nothing exclusive, so it shares the node's regular workers.
     *
     * Monopolistic workers are pre-forked rather than spawned per placement, which would
     * cap a node at however many it forked at boot — and after a failover a node must take
     * the whole fleet at once.
     *
     * @return bool False: run in a shared regular worker
     */
    public function requiresMonopolisticProcess(): bool
    {
        return false;
    }

    /**
     * A data-plane agent, not a leader-singleton: the leader places it on another node.
     *
     * @return bool False: may run on a node that is not the cluster leader
     */
    public function requiresClusterLeadership(): bool
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
        return [ClusterCapability::WORKER];
    }
}
