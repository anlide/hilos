<?php

declare(strict_types=1);

namespace Demo\Cluster\Core\Agent\Daemon;

use Demo\Cluster\Constants\AgentType;
use Demo\Cluster\Constants\ClusterCapability;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/**
 * Daemon proxy for the placeable no-op worker agent.
 *
 * Its three flags define how the cluster treats it:
 * - monopolistic: it claims its own monopolistic worker on the host node;
 * - NOT a cluster-singleton: it is a per-node/data-plane agent the leader places
 *   remotely, so it must be startable on a node that is not the leader;
 * - capability-gated: it runs only on a node advertising the WORKER capability,
 *   which the leader hard-checks before placing it.
 */
final class WorkerAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = AgentType::WORKER;

    /**
     * The agent owns single-writer presence, so it takes a dedicated monopolistic worker.
     *
     * @return bool True: run in a monopolistic worker
     */
    public function requiresMonopolisticProcess(): bool
    {
        return true;
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
