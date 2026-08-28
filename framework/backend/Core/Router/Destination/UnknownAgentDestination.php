<?php

declare(strict_types=1);

namespace Hilos\Core\Router\Destination;

use Hilos\Cluster\Placement\AgentLocationKind;
use Hilos\Core\Daemon\DaemonManager;

/**
 * UnknownAgentDestination - Names an agent nobody on this node knows how to reach.
 *
 * The router contributes this in place of an {@see AgentDestination} when the placement lookup
 * answers {@see AgentLocationKind::Unknown}: the agent is not hosted here, and no node has been
 * named as its host — a follower that has not yet received the leader's placement view, a
 * cluster mid-election with no leader to ask, or an agent nothing has placed anywhere.
 *
 * It carries the target agent and no node, because there is no node to carry: naming one would
 * be inventing an address. {@see DaemonManager} drops it with a log line, and answers the
 * browser when the dropped signal was a page subscription somebody is waiting on.
 *
 * Its whole reason to exist is to be distinguishable from {@see AgentDestination}. Before it,
 * an unknown address collapsed into the local one and the signal was delivered into this node's
 * own workers, which run no such agent — a delivery that succeeds and reaches nobody.
 *
 * Like every {@see Destination} it is computed and consumed inside the daemon and never crosses
 * a process boundary.
 */
final class UnknownAgentDestination implements AgentAddressedDestination
{
    /**
     * @param string $agentType Target agent type whose host is unknown
     * @param ?string $agentIndex Agent instance index, or null for unindexed agents
     */
    public function __construct(
        public readonly string $agentType,
        public readonly ?string $agentIndex = null,
    ) {
    }
}
