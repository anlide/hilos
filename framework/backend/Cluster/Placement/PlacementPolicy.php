<?php

declare(strict_types=1);

namespace Hilos\Cluster\Placement;

/**
 * The node-selection policy (HIL-182): given an agent's requirement and a set of candidate
 * nodes, chooses which one should host it.
 *
 * This is the seam the placement coordinator delegates the "which node" question to, both for
 * the automatic {@see ClusterPlacement::placeAgentOnBestNode()} entry and for re-placing an
 * orphaned agent during failover. HIL-179 answered it with "the first capable node"; this
 * contract lets a real policy weigh a node's declared capacities against the agent's demand.
 * A policy never places anything — it only picks — so it stays pure and testable, and the
 * coordinator keeps ownership of the hard capability gate and the placement frames.
 *
 * The default is {@see BestFitPlacementPolicy}. A project may supply its own to change how
 * capable nodes are ranked.
 */
interface PlacementPolicy
{
    /**
     * Chooses the node best suited to host an agent, or null when none is a fit.
     *
     * The implementation is the arbiter of both the hard gate (a candidate that lacks a
     * required tag or falls below a minimum is not eligible) and the soft ranking among the
     * eligible ones. The choice must be deterministic so re-elections and repeated calls
     * converge on the same node — but it is passed the current occupancy, so "deterministic"
     * means a function of the placement view, not the same node every time regardless of what
     * already runs there.
     *
     * @param list<string> $requiredTags Boolean capability tags the agent must have
     * @param ResourceProfile $profile Numeric hard minimums and soft preferences of the agent
     * @param array<string, NodeCapacities> $candidates Candidate nodes' capacities keyed by node id
     * @param array<string, int> $hosted Agents each candidate already hosts, keyed by node id;
     *     absent means none, so a policy that ignores occupancy keeps its old behaviour
     * @return ?string Chosen node id, or null when no candidate satisfies the hard gate
     */
    public function selectNode(
        array $requiredTags,
        ResourceProfile $profile,
        array $candidates,
        array $hosted = [],
    ): ?string;
}
