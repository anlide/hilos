<?php

declare(strict_types=1);

namespace Demo\Cluster\Core\Daemon;

use Demo\Cluster\Core\Router\ClusterSignalRouter;
use Demo\Cluster\Core\Socket\Server\ClusterWorkerServer;
use Demo\Cluster\Hilos;
use Hilos\Cluster\Probe\ProbeFleetSupervisor;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Daemon\DaemonContext;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Daemon\Module\DaemonModule;
use Hilos\Core\Daemon\Module\PeerModule;
use Hilos\Core\Router\SignalRouter;
use Hilos\Environment\Exception\EnvException;
use Hilos\Socket\Server\CommandServer;
use Hilos\Socket\Server\HttpServer;
use Hilos\Socket\Server\ServerInterface;

/**
 * ClusterDaemonManager - Main daemon manager for the cluster demo.
 *
 * The standard factory wiring and nothing more. Placing the probe fleet the harness observes is
 * the framework's ({@see ProbeFleetSupervisor}, HIL-1211): the leader places it wherever a
 * project lists it and a probe may start.
 */
final class ClusterDaemonManager extends DaemonManager
{
    /** @var ClusterWorkerServer Worker server, held while composing so the server set below names the built instance */
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
            Hilos::$env[EnvConstants::WORKER_COMM_HOST]->string(),
            Hilos::$env[EnvConstants::WORKER_COMM_PORT]->int(),
            $context->workerScript(),
            $context->bootstrapDir,
            $this->getAgentManagerDaemon(),
        );

        return [
            new HttpServer(
                Hilos::$env[EnvConstants::HTTP_STATUS_HOST]->string(),
                Hilos::$env[EnvConstants::HTTP_STATUS_PORT]->int(),
            ),
            $this->workerServer,
            new CommandServer(
                Hilos::$env[EnvConstants::COMMAND_HOST]->string(),
                Hilos::$env[EnvConstants::COMMAND_PORT]->int(),
            ),
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
}
