<?php

declare(strict_types=1);

namespace Demo\Preferans\Core\Daemon;

use Demo\Preferans\Core\Router\PreferansSignalRouter;
use Demo\Preferans\Core\Socket\Server\PreferansWebSocketServer;
use Demo\Preferans\Core\Socket\Server\PreferansWorkerServer;
use Demo\Preferans\Hilos;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Daemon\DaemonContext;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Daemon\Module\BuildTimestampModule;
use Hilos\Core\Daemon\Module\DaemonModule;
use Hilos\Core\Daemon\Module\PeerModule;
use Hilos\Core\Router\SignalRouter;
use Hilos\Environment\Exception\EnvException;
use Hilos\Socket\Server\CommandServer;
use Hilos\Socket\Server\HttpServer;
use Hilos\Socket\Server\ServerInterface;

/**
 * PreferansDaemonManager - Main daemon manager for the preferans demo.
 *
 * Extends framework DaemonManager: declares the preferans server set (HTTP status, worker,
 * WebSocket), the cluster peer transport and the build-timestamp module. No cron rules yet.
 */
final class PreferansDaemonManager extends DaemonManager
{
    /** @var PreferansWorkerServer Worker server, held while composing so the server set below names the built instance */
    private PreferansWorkerServer $workerServer;

    /**
     * Create signal router instance.
     *
     * @return SignalRouter Preferans signal router instance
     */
    protected function createSignalRouter(): SignalRouter
    {
        return new PreferansSignalRouter();
    }

    /**
     * Create agent manager daemon instance.
     *
     * @return AgentManagerDaemon Preferans agent manager daemon instance
     */
    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new PreferansAgentManagerDaemon();
    }

    /**
     * The preferans server set: HTTP status, worker, and WebSocket servers.
     *
     * @param DaemonContext $context Resolved path context
     * @return iterable<ServerInterface> Servers to register, in bind order
     * @throws EnvException When a server host/port env value cannot be read
     */
    protected function createServers(DaemonContext $context): iterable
    {
        $this->workerServer = new PreferansWorkerServer(
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
            new PreferansWebSocketServer(
                Hilos::$env[EnvConstants::WEBSOCKET_HOST]->string(),
                Hilos::$env[EnvConstants::WEBSOCKET_PORT]->int(),
            ),
            // Unconditional, like every other server here: without it not one framework CLI
            // command reaches this daemon - not the admin grant, not ping, not the test
            // levers - because the command channel is the only way in for all of them.
            new CommandServer(
                Hilos::$env[EnvConstants::COMMAND_HOST]->string(),
                Hilos::$env[EnvConstants::COMMAND_PORT]->int(),
            ),
        ];
    }

    /**
     * The optional cluster peer transport and build timestamp for the handshake welcome frame.
     *
     * @param DaemonContext $context Resolved path context
     * @return iterable<DaemonModule> Modules to consider, checked via isActive() before register()
     * @throws EnvException When the frontend dist path cannot be resolved
     */
    protected function modules(DaemonContext $context): iterable
    {
        return [
            new PeerModule(),
            new BuildTimestampModule($context->frontendDistPath()),
        ];
    }
}
