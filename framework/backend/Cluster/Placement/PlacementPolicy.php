<?php

declare(strict_types=1);

namespace Hilos\Cluster\Placement;

use Hilos\Core\Daemon\DaemonManager;

/**
 * The node-selection policy (HIL-182, HIL-448): given an agent's requirement and cost and the
 * online candidate nodes, chooses which one should host it.
 *
 * This is the seam the placement coordinator delegates the "which node" question to, both for
 * the automatic {@see ClusterPlacement::placeAgentOnBestNode()} entry and for re-placing an
 * orphaned agent during failover. Each {@see PlacementCandidate} already carries what the node
 * declares, what its live placements hold and how many they are, so a policy never reads the
 * registry itself. A policy never places anything — it only picks — so it stays pure and
 * testable, and the coordinator keeps ownership of the placement frames.
 *
 * The default is {@see BestFitPlacementPolicy}. A project may supply its own to change how
 * candidates are ranked, by overriding {@see DaemonManager::createPlacementPolicy()}: the
 * daemon hands what it returns to the cluster facade at boot, and the transport builds the
 * coordinator against it.
 */
interface PlacementPolicy
{
    /**
     * Chooses the node best suited to host an agent, or null when none is a fit.
     *
     * The implementation is the arbiter of both the hard gate and the ranking among the
     * candidates that clear it. The gate is {@see PlacementCandidate::accepts()} — the same one a
     * placement that names its node passes — so a policy that skips it would pick a node the
     * placement then refuses. The choice must be deterministic so re-elections and repeated
     * calls converge on the same node — "deterministic" means a function of the candidates,
     * whose occupancy changes as agents land, not the same node every time.
     *
     * @param list<string> $requiredTags Boolean capability tags the agent must have
     * @param ResourceProfile $cost Resource cost of the agent
     * @param array<string, PlacementCandidate> $candidates Online candidate nodes keyed by node id
     * @return ?string Chosen node id, or null when no candidate clears the gate
     */
    public function selectNode(array $requiredTags, ResourceProfile $cost, array $candidates): ?string;
}
