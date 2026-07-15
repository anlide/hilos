<?php

declare(strict_types=1);

namespace Hilos\Cluster\Placement;

use Hilos\Cluster\ClusterContext;
use Hilos\Core\Daemon\DaemonManager;

/**
 * Seam that receives placement-degradation events from the leader-side coordinator.
 *
 * When failover finds no capable+online node to host an orphaned agent, the placement
 * coordinator marks it {@see PlacementState::Unplaced} and reports it here so the project
 * can react (alert, hold work, adjust capacity). Symmetric to the membership and leadership
 * observers: {@see ClusterContext} exposes the registered observer, and the
 * {@see DaemonManager} registers itself at start, turning this into a project-overridable
 * hook. The leader retries the placement automatically when a capable node joins, so this
 * is a notification, not a request for the project to place the agent itself.
 */
interface PlacementObserver
{
    /**
     * Called when an orphaned agent could not be re-placed on any capable+online node.
     *
     * @param string $agentType Agent type left unplaced
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     */
    public function onPlacementDegraded(string $agentType, ?string $agentIndex): void;
}
