<?php

declare(strict_types=1);

namespace Demo\Cluster\Core\Daemon;

use Demo\Cluster\Constants\AgentType;
use Demo\Cluster\Core\Router\ClusterSignalRouter;
use Demo\Cluster\Core\Socket\Server\ClusterWorkerServer;
use Demo\Cluster\Hilos;
use Hilos\Cluster\Placement\ClusterPlacement;
use Hilos\Cluster\Placement\PlacementState;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Daemon\DaemonContext;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Daemon\Module\DaemonModule;
use Hilos\Core\Daemon\Module\PeerModule;
use Hilos\Core\Http\StatusHandler;
use Hilos\Core\Router\SignalRouter;
use Hilos\Environment\Exception\EnvException;
use Hilos\Socket\Server\CommandServer;
use Hilos\Socket\Server\HttpServer;
use Hilos\Socket\Server\ServerInterface;
use Hilos\Utils\Logger;

/**
 * ClusterDaemonManager - Main daemon manager for the cluster demo.
 *
 * Beyond the standard factory wiring it drives placement of the demo's worker
 * fleet. Once this node is leader and the mesh has settled, it asks the framework's
 * best-fit policy (HIL-182) to place every fleet member on the strongest capable
 * data-plane node, and lets the framework's failover defaults (HIL-183) re-place the
 * lost node's share when that node dies. Placement is idempotent per member — a
 * tracked record, in any state, suppresses re-placing — so the leader never
 * double-runs a member, and a fresh leader re-derives the fleet from the mesh.
 */
final class ClusterDaemonManager extends DaemonManager
{
    /** @var float Heartbeats to wait after winning leadership before placing, so the mesh rebuild settles */
    private const float PLACE_SETTLE_HEARTBEATS = 4.0;

    /** @var int Worker agents the leader keeps placed across the data plane */
    private const int WORKER_FLEET_SIZE = 10;

    /** @var float Seconds between attempts to re-place a fleet member whose start failed */
    private const float FAILED_RETRY_INTERVAL_SEC = 5.0;

    /** @var float Microtime a failed fleet member may be re-placed again */
    private float $retryFailedAt = 0.0;

    /** @var ?float Placement view has settled after this microtime; null while not leader or still settling */
    private ?float $placeSettleDeadline = null;

    /** @var ClusterWorkerServer Worker server, stashed while composing so the status route can read its counts */
    private ClusterWorkerServer $workerServer;

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
     * The cluster node server set: HTTP status, worker, and CLI command servers.
     *
     * Headless — no WebSocket, no frontend. The peer transport that forms the mesh is
     * added by {@see PeerModule} when cluster mode is enabled.
     *
     * @param DaemonContext $context Resolved path context
     * @return iterable<ServerInterface> Servers to register, in bind order
     * @throws EnvException When a server host/port env value cannot be read
     */
    protected function createServers(DaemonContext $context): iterable
    {
        $this->workerServer = new ClusterWorkerServer(
            Hilos::$env[EnvConstants::WORKER_COMM_HOST],
            Hilos::$env->int(EnvConstants::WORKER_COMM_PORT),
            $context->workerScript(),
            $context->bootstrapDir,
            $this->getAgentManagerDaemon(),
        );

        return [
            new HttpServer(
                Hilos::$env[EnvConstants::HTTP_STATUS_HOST],
                Hilos::$env->int(EnvConstants::HTTP_STATUS_PORT),
            ),
            $this->workerServer,
            new CommandServer(
                Hilos::$env[EnvConstants::COMMAND_HOST],
                Hilos::$env->int(EnvConstants::COMMAND_PORT),
            ),
        ];
    }

    /**
     * The cluster node HTTP routes: the shared readiness/health status endpoint.
     *
     * @param DaemonContext $context Resolved path context
     * @return iterable<array{0: string, 1: string, 2: callable}> Route triples [method, path, handler]
     */
    protected function httpRoutes(DaemonContext $context): iterable
    {
        return [
            ['GET', '/status', new StatusHandler($this->workerServer)],
        ];
    }

    /**
     * The cluster node modules: the peer transport that forms the mesh.
     *
     * @param DaemonContext $context Resolved path context
     * @return iterable<DaemonModule> Modules to consider, checked via isActive() before register()
     */
    protected function modules(DaemonContext $context): iterable
    {
        return [
            new PeerModule(),
        ];
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
     * Leader per-tick hook: once the settle window has elapsed, ensure the worker fleet is
     * placed. Cheap and idempotent — one registry lookup per fleet member on most ticks.
     */
    protected function onTickLeaderMaster(): void
    {
        if ($this->placeSettleDeadline === null || microtime(true) < $this->placeSettleDeadline) {
            return;
        }

        $this->ensureWorkerFleetPlaced();
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
    private function ensureWorkerFleetPlaced(): void
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
                if ($record->agentType !== AgentType::WORKER || $record->agentIndex === null) {
                    continue;
                }
                if ($retryFailed && $record->state === PlacementState::Failed) {
                    continue;
                }

                $tracked[$record->agentIndex] = true;
            }

            for ($index = 0; $index < self::WORKER_FLEET_SIZE; $index++) {
                $agentIndex = (string)$index;
                if (isset($tracked[$agentIndex])) {
                    continue;
                }

                $placement->placeAgentOnBestNode(AgentType::WORKER, $agentIndex);
            }
        } catch (\Throwable $e) {
            Logger::warning("Cluster demo could not place the WORKER fleet: {$e->getMessage()}");
        }
    }
}
