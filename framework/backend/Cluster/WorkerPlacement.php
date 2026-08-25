<?php

declare(strict_types=1);

namespace Hilos\Cluster;

use Hilos\Cluster\Placement\AgentLocation;
use Hilos\Cluster\Placement\AgentLocationKind;
use Hilos\Cluster\Placement\ClusterPlacement;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\Destination\RemoteAgentDestination;
use Hilos\Core\Router\Destination\UnknownAgentDestination;
use Hilos\Environment\Exception\EnvException;

/**
 * Read-only placement-lookup seam the signal router consults to learn where an agent runs.
 *
 * This is the narrow contract the router reads from: for a resolved agent target it asks
 * {@see locate()} where that agent lives, and turns the answer into one of three destinations —
 * a local {@see AgentDestination}, a {@see RemoteAgentDestination} the daemon forwards over the
 * peer channel, or an {@see UnknownAgentDestination} that is deliverable to nobody. The router
 * never owns or mutates placement; the truth of where each agent runs is owned by the placement
 * coordinator ({@see ClusterPlacement}, HIL-179), which implements this seam over its placement
 * view. A test supplies a fake so the routing post-pass can be exercised without a live cluster.
 *
 * The lookup answers {@see AgentLocationKind::Unknown} rather than falling back to local
 * delivery (HIL-670): "the agent is here" and "nobody has told me where the agent is" are
 * different facts, and a lookup that returned null for both sent work to a node running no such
 * agent, silently. Off-cluster nothing registers a lookup at all and the post-pass is inert.
 */
interface WorkerPlacement
{
    /**
     * Answers where an agent runs: on this node, on a named node, or nowhere known.
     *
     * @param string $agentType Agent type to look up
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @return AgentLocation Location of that agent as this node currently knows it
     * @throws EnvException When an implementation reads cluster configuration and it is invalid
     */
    public function locate(string $agentType, ?string $agentIndex): AgentLocation;
}
