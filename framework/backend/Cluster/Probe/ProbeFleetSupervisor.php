<?php

declare(strict_types=1);

namespace Hilos\Cluster\Probe;

use Hilos\Cluster\Placement\ClusterPlacement;
use Hilos\Cluster\Placement\PlacementState;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Utils\Logger;
use Throwable;

/**
 * ProbeFleetSupervisor - the leader's keeper of the cluster probe fleet ({@see FleetProbeAgent}).
 *
 * Once this node is leader and the mesh has settled, it hands every fleet member to the
 * framework's best-fit policy (HIL-182, HIL-448). A member declares the worker capability and no
 * cost at all, so the policy gates on the tag and on a node having declared some capacity, and
 * then spreads the fleet by head count: the strongest node is a tie-breaker far down that list,
 * not the target. Failover defaults (HIL-183) re-place the lost node's share when a node dies.
 * Placement is idempotent per member - a tracked record, in any state, suppresses re-placing -
 * so the leader never double-runs a member, and a fresh leader re-derives the fleet from the
 * mesh.
 *
 * Its own pass beside the framework's policy sweep because that sweep leaves indexed pools alone:
 * how many members a pool has is known only to whoever declared it, and this pool is the
 * framework's ({@see ClusterProbe::FLEET_SIZE}). It does nothing where the fleet is not listed in
 * the project's AGENTS or where no probe may start ({@see ClusterProbe::mayRunHere()}).
 *
 * Held by the {@see DaemonManager} and driven from its master loop: the leadership hooks arm and
 * disarm the settle window, and the leader block ticks it.
 */
final class ProbeFleetSupervisor
{
    /** @var float Heartbeats to wait after winning leadership before placing, so the mesh rebuild settles */
    private const float PLACE_SETTLE_HEARTBEATS = 4.0;

    /** @var float Seconds between attempts to re-place a fleet member whose start failed */
    private const float FAILED_RETRY_INTERVAL_SEC = 5.0;

    /** @var float Microtime a failed fleet member may be re-placed again */
    private float $retryFailedAt = 0.0;

    /** @var ?float Placement view has settled after this microtime; null while not leader or still settling */
    private ?float $placeSettleDeadline = null;

    /**
     * Arms the settle window a fresh leader waits out before it places the fleet itself.
     *
     * The window lets the placement rebuild's query/report round-trip complete, so a leader that
     * inherits an already-placed member adopts it from the mesh instead of placing a duplicate.
     *
     * @throws EnvException When the heartbeat-interval env value cannot be read
     */
    public function onBecameLeader(): void
    {
        $heartbeatSec = Hilos::$env[EnvConstants::CLUSTER_HEARTBEAT_INTERVAL_MS]->int() / TimeConstants::MS_PER_SECOND;
        $this->placeSettleDeadline = microtime(true) + $heartbeatSec * self::PLACE_SETTLE_HEARTBEATS;
    }

    /**
     * Disarms the settle window, so a demoted node never drives placement.
     */
    public function onLostLeadership(): void
    {
        $this->placeSettleDeadline = null;
    }

    /**
     * Leader per-tick pass: once the settle window has elapsed, ensures the fleet is placed.
     *
     * Cheap and idempotent - one registry walk and one lookup per fleet member on most ticks - and
     * empty unless the fleet is listed and a probe may start on this node.
     */
    public function tick(): void
    {
        if ($this->placeSettleDeadline === null || microtime(true) < $this->placeSettleDeadline) {
            return;
        }

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
     * places on the winner, or does nothing when none is a fit yet. A record in any live
     * state (including Unplaced, which the framework retries on the next capable join)
     * suppresses placement, so this never fights failover or double-runs a member. A member
     * whose start Failed is the exception: nothing else retries it, so this supervisor
     * re-places it once per retry interval until it comes up.
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

            $now = microtime(true);
            $retryFailed = $now >= $this->retryFailedAt;
            if ($retryFailed) {
                $this->retryFailedAt = $now + self::FAILED_RETRY_INTERVAL_SEC;
            }

            $tracked = [];
            foreach ($placement->registry()->all() as $record) {
                if ($record->agentType !== HilosAgentType::HILOS_PROBE_FLEET || $record->agentIndex === null) {
                    continue;
                }
                if ($retryFailed && $record->state === PlacementState::Failed) {
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
