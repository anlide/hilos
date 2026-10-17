<?php

declare(strict_types=1);

namespace Hilos\Cluster\Probe;

use Hilos\Cluster\Placement\ClusterPlacement;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Hilos;
use Hilos\Utils\Logger;
use Throwable;

/**
 * ProbeFleetSupervisor - the leader's keeper of the cluster probe fleet ({@see FleetProbeAgent}).
 *
 * While this node leads, it hands every fleet member to the framework's best-fit policy (HIL-182,
 * HIL-448). A member declares the worker capability and no project cost, so placement charges it
 * its one mandatory slot (HIL-447): the policy gates on the tag, on a node having declared some
 * capacity and on a free slot, and then spreads the fleet by each node's share of the slots it
 * declares, the leader last among equal shares; the strongest node is a tie-breaker far down that
 * list, not the target. Failover defaults (HIL-183) re-place the lost node's share when a node
 * dies. Placement is idempotent per member - a tracked record, in any state, suppresses
 * re-placing - so the leader never double-runs a member, and a fresh leader re-derives the fleet
 * from the mesh.
 *
 * Its own pass beside the framework's policy sweep because that sweep leaves indexed pools alone:
 * how many members a pool has is known only to whoever declared it, and this pool is the
 * framework's ({@see ClusterProbe::FLEET_SIZE}). It does nothing where the fleet is not listed in
 * the project's AGENTS or where no probe may start ({@see ClusterProbe::mayRunHere()}).
 *
 * A fresh leader's placements wait for its rebuild - every node's report, or its failover grace
 * ({@see ClusterPlacement}, HIL-1217) - so a leader inheriting a placed member adopts it instead
 * of placing a copy. The wait is the placement coordinator's, not this pass's: it waits for the
 * fact rather than for a number of heartbeats.
 *
 * Held by the {@see DaemonManager} and driven from its master loop, by the block that runs only
 * on the leader.
 */
final class ProbeFleetSupervisor
{
    /**
     * Leader per-tick pass: ensures the fleet is placed.
     *
     * Cheap and idempotent - one registry walk and one lookup per fleet member on most ticks - and
     * empty unless the fleet is listed and a probe may start on this node.
     */
    public function tick(): void
    {
        if (!isset(Hilos::appClass()::AGENTS[HilosAgentType::HILOS_PROBE_FLEET]) || !ClusterProbe::mayRunHere()) {
            return;
        }

        $this->ensureFleetPlaced();
    }

    /**
     * Places every fleet member the leader is not already tracking on the best-fit node.
     *
     * Delegates node choice to the framework's best-fit policy (HIL-182) via
     * {@see ClusterPlacement::placeAgentOnBestNode()}: it ranks the online capable nodes and
     * places on the winner, or records Unplaced when none is a fit yet. Every tracked record,
     * including Failed and Unplaced, suppresses this declaration. ClusterPlacement owns retries;
     * this supervisor declares only the missing indexes of its own 0..9 fleet, never extras.
     */
    private function ensureFleetPlaced(): void
    {
        // Runs on the master loop, so the whole placement read/write is guarded: a
        // registry hiccup or a rejected placement is logged, never propagated. A member
        // that throws leaves the rest for the next tick, which retries from where it stopped.
        try {
            $placement = Hilos::$cluster?->placement();
            if ($placement === null) {
                return;
            }

            $tracked = [];
            foreach ($placement->registry()->all() as $record) {
                if ($record->agentType !== HilosAgentType::HILOS_PROBE_FLEET || $record->agentIndex === null) {
                    continue;
                }

                $tracked[$record->agentIndex] = true;
            }

            for ($index = 0; $index < ClusterProbe::FLEET_SIZE; $index++) {
                $agentIndex = (string)$index;
                if (isset($tracked[$agentIndex])) {
                    continue;
                }

                $placement->placeAgentOnBestNode(HilosAgentType::HILOS_PROBE_FLEET, $agentIndex);
            }
        } catch (Throwable $e) {
            Logger::warning("Could not place the cluster probe fleet: {$e->getMessage()}");
        }
    }
}
