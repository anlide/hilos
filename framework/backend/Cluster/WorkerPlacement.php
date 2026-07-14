<?php

declare(strict_types=1);

namespace Hilos\Cluster;

/**
 * Read-only placement-lookup seam the signal router consults to learn which node hosts
 * an agent.
 *
 * This is the narrow contract the router reads from: for a resolved agent target it asks
 * {@see nodeFor()} whether that agent lives on another node, and — only when it does —
 * turns the local {@see \Hilos\Core\Router\Destination\AgentDestination} into a
 * {@see \Hilos\Core\Router\Destination\RemoteAgentDestination} the daemon forwards over
 * the peer channel. The router never owns or mutates placement; the truth of where each
 * agent runs is owned by the placement coordinator ({@see Placement\ClusterPlacement},
 * HIL-179), which implements this seam over its placement view. A test supplies a fake so
 * the routing post-pass can be exercised without a live cluster.
 *
 * The self-node short-circuit lives behind this contract: an agent hosted locally (or one
 * with no known remote placement) returns null, so the router keeps delivering it locally
 * exactly as before. Off-cluster nothing registers a lookup and the post-pass is inert.
 */
interface WorkerPlacement
{
    /**
     * Returns the id of the node hosting an agent when it is a node other than this one;
     * null when the agent runs locally, is not placed remotely, or its placement is unknown.
     *
     * @param string $agentType Agent type to look up
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @return ?string Hosting node id when remote, or null for local / unknown
     */
    public function nodeFor(string $agentType, ?string $agentIndex): ?string;
}
