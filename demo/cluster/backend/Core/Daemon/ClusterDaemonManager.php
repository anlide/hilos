<?php

declare(strict_types=1);

namespace Demo\Cluster\Core\Daemon;

use Demo\Cluster\Constants\AgentType;
use Demo\Cluster\Constants\ClusterCapability;
use Demo\Cluster\Core\Router\ClusterSignalRouter;
use Demo\Cluster\Hilos;
use Hilos\Cluster\Exception\ClusterConfigurationException;
use Hilos\Cluster\Exception\ClusterDisabledException;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Router\SignalRouter;
use Hilos\Environment\Exception\EnvException;
use Hilos\Utils\Logger;

/**
 * ClusterDaemonManager - Main daemon manager for the cluster demo.
 *
 * Beyond the standard factory wiring it drives placement of the demo's single
 * no-op agent. Automatic node-selection policy is a later cluster slice (HIL-182),
 * so the demo supplies the trigger the harness needs: once this node is leader and
 * the mesh has settled, it places the WORKER agent onto a capable data-plane node
 * via the HIL-179 placement primitive, and lets the framework's failover defaults
 * (HIL-183) re-place it when that node is lost. Placement is idempotent — a single
 * tracked record, in any state, suppresses re-placing — so the leader never
 * double-runs the agent, and a fresh leader re-derives placement from the mesh.
 */
final class ClusterDaemonManager extends DaemonManager
{
    /** @var float Heartbeats to wait after winning leadership before placing, so the mesh rebuild settles */
    private const float PLACE_SETTLE_HEARTBEATS = 4.0;

    /** @var ?string Placement view has settled after this microtime; null while not leader or still settling */
    private ?float $placeSettleDeadline = null;

    /**
     * Create signal router instance.
     *
     * @return SignalRouter Cluster signal router instance
     */
    protected function createSignalRouter(): SignalRouter
    {
        return new ClusterSignalRouter();
    }

    /**
     * Create agent manager daemon instance.
     *
     * @return AgentManagerDaemon Cluster agent manager daemon instance
     */
    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new ClusterAgentManagerDaemon();
    }

    /**
     * On winning leadership, rebuild the placement view (framework default) and arm a
     * settle window before this leader places the demo agent itself.
     *
     * The settle window lets the rebuild query/report round-trip complete, so a leader
     * that inherits an already-placed agent adopts it from the mesh instead of placing a
     * duplicate.
     *
     * @param int $term Election term in which leadership was won
     * @throws EnvException When the heartbeat-interval env value cannot be read
     */
    public function onBecameLeader(int $term): void
    {
        parent::onBecameLeader($term);

        $heartbeatSec = Hilos::$env->int(EnvConstants::CLUSTER_HEARTBEAT_INTERVAL_MS) / 1000.0;
        $this->placeSettleDeadline = microtime(true) + $heartbeatSec * self::PLACE_SETTLE_HEARTBEATS;
    }

    /**
     * On losing leadership, drop the placement view (framework default) and disarm the
     * settle window so a demoted node never drives placement.
     *
     * @param int $term Election term in which leadership was held and then lost
     */
    public function onLostLeadership(int $term): void
    {
        parent::onLostLeadership($term);

        $this->placeSettleDeadline = null;
    }

    /**
     * Leader per-tick hook: once the settle window has elapsed, ensure the demo agent is
     * placed. Cheap and idempotent — a single registry lookup on most ticks.
     */
    protected function onTickLeaderMaster(): void
    {
        if ($this->placeSettleDeadline === null || microtime(true) < $this->placeSettleDeadline) {
            return;
        }

        $this->ensureWorkerPlaced();
    }

    /**
     * Places the WORKER agent on a capable data-plane node when it is not already tracked.
     *
     * A record in any state (including Unplaced, which the framework retries on the next
     * capable join) suppresses placement, so this never fights failover or double-runs the
     * agent. When no capable node is online yet, it silently retries on the next leader tick.
     */
    private function ensureWorkerPlaced(): void
    {
        // Runs on the master loop, so the whole placement read/write is guarded: a
        // registry hiccup or a rejected placement is logged, never propagated.
        try {
            $placement = Hilos::$cluster?->placement();
            if ($placement === null || $placement->registry()->get(AgentType::WORKER) !== null) {
                return;
            }

            $target = $this->pickWorkerNode();
            if ($target !== null) {
                $placement->placeAgentOnNode(AgentType::WORKER, null, $target);
            }
        } catch (\Throwable $e) {
            Logger::warning("Cluster demo could not place WORKER agent: {$e->getMessage()}");
        }
    }

    /**
     * Picks the first online node (in id order) that advertises the WORKER capability.
     *
     * Deterministic so re-elections converge on the same host; the leader's placement
     * primitive hard-checks the capability again before sending the frame.
     *
     * @return ?string Chosen data-plane node id, or null when none is online yet
     * @throws ClusterDisabledException When cluster mode is disabled
     * @throws ClusterConfigurationException When enabled but node config is missing or invalid
     * @throws EnvException When a cluster env value cannot be read
     */
    private function pickWorkerNode(): ?string
    {
        $registry = Hilos::$cluster?->registry();
        if ($registry === null) {
            return null;
        }

        $candidates = [];
        foreach ($registry->snapshot() as $node) {
            if ($node->online && in_array(ClusterCapability::WORKER, $node->capabilities, true)) {
                $candidates[] = $node->nodeId;
            }
        }

        sort($candidates);

        return $candidates[0] ?? null;
    }
}
