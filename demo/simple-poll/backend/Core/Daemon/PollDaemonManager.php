<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Core\Daemon;

use Demo\SimplePoll\Core\Router\PollSignalRouter;
use Demo\SimplePoll\Core\Socket\Server\PollWebSocketServer;
use Demo\SimplePoll\Core\Socket\Server\PollWorkerServer;
use Demo\SimplePoll\Hilos;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HttpConstants;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Daemon\DaemonContext;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Daemon\Module\BuildTimestampModule;
use Hilos\Core\Daemon\Module\DaemonModule;
use Hilos\Core\Http\StatusHandler;
use Hilos\Core\Router\SignalRouter;
use Hilos\Environment\Exception\EnvException;
use Hilos\Socket\Server\HttpServer;
use Hilos\Socket\Server\ServerInterface;

/**
 * PollDaemonManager - Main daemon manager for the simple-poll demo.
 *
 * Extends framework DaemonManager: declares the poll server set (HTTP status, worker,
 * WebSocket), the shared status route, and the build-timestamp module. No cron rules yet.
 */
final class PollDaemonManager extends DaemonManager
{
    /** @var PollWorkerServer Worker server, stashed while composing so the status route can read its counts */
    private PollWorkerServer $workerServer;

    /**
     * Create signal router instance.
     *
     * @return SignalRouter Poll signal router instance
     */
    protected function createSignalRouter(): SignalRouter
    {
        return new PollSignalRouter();
    }

    /**
     * Create agent manager daemon instance.
     *
     * @return AgentManagerDaemon Poll agent manager daemon instance
     */
    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new PollAgentManagerDaemon();
    }

    /**
     * The poll server set: HTTP status, worker, and WebSocket servers.
     *
     * @param DaemonContext $context Resolved path context
     * @return iterable<ServerInterface> Servers to register, in bind order
     * @throws EnvException When a server host/port env value cannot be read
     */
    protected function createServers(DaemonContext $context): iterable
    {
        $this->workerServer = new PollWorkerServer(
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
            new PollWebSocketServer(
                Hilos::$env[EnvConstants::WEBSOCKET_HOST],
                Hilos::$env->int(EnvConstants::WEBSOCKET_PORT),
            ),
        ];
    }

    /**
     * The poll HTTP routes: the shared status endpoint.
     *
     * @param DaemonContext $context Resolved path context
     * @return iterable<array{0: string, 1: string, 2: callable}> Route triples [method, path, handler]
     */
    protected function httpRoutes(DaemonContext $context): iterable
    {
        return [
            [HttpConstants::METHOD_GET, '/status', new StatusHandler($this->workerServer)],
        ];
    }

    /**
     * The poll modules: build-timestamp exposure for the handshake welcome frame.
     *
     * @param DaemonContext $context Resolved path context
     * @return iterable<DaemonModule> Modules to consider, checked via isActive() before register()
     * @throws EnvException When the frontend dist path cannot be resolved
     */
    protected function modules(DaemonContext $context): iterable
    {
        return [
            new BuildTimestampModule($context->frontendDistPath()),
        ];
    }
}
