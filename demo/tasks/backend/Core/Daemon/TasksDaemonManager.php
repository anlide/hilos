<?php

declare(strict_types=1);

namespace Demo\Tasks\Core\Daemon;

use Demo\Tasks\Core\Router\TasksSignalRouter;
use Demo\Tasks\Core\Socket\Server\TasksWebSocketServer;
use Demo\Tasks\Core\Socket\Server\TasksWorkerServer;
use Demo\Tasks\Hilos;
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
use Hilos\Socket\Server\CommandServer;
use Hilos\Socket\Server\HttpServer;
use Hilos\Socket\Server\ServerInterface;

/**
 * TasksDaemonManager - Main daemon manager for the tasks demo.
 *
 * Extends framework DaemonManager: declares the tasks server set (HTTP status, worker,
 * WebSocket), the shared status route, and the build-timestamp module. No cron rules yet.
 */
final class TasksDaemonManager extends DaemonManager
{
    /** @var TasksWorkerServer Worker server, stashed while composing so the status route can read its counts */
    private TasksWorkerServer $workerServer;

    /**
     * Create signal router instance.
     *
     * @return SignalRouter Tasks signal router instance
     */
    protected function createSignalRouter(): SignalRouter
    {
        return new TasksSignalRouter();
    }

    /**
     * Create agent manager daemon instance.
     *
     * @return AgentManagerDaemon Tasks agent manager daemon instance
     */
    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new TasksAgentManagerDaemon();
    }

    /**
     * The tasks server set: HTTP status, worker, and WebSocket servers.
     *
     * @param DaemonContext $context Resolved path context
     * @return iterable<ServerInterface> Servers to register, in bind order
     * @throws EnvException When a server host/port env value cannot be read
     */
    protected function createServers(DaemonContext $context): iterable
    {
        $this->workerServer = new TasksWorkerServer(
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
            new TasksWebSocketServer(
                Hilos::$env[EnvConstants::WEBSOCKET_HOST],
                Hilos::$env->int(EnvConstants::WEBSOCKET_PORT),
            ),
            // Unconditional, like every other server here: without it not one framework CLI
            // command reaches this daemon - not the admin grant, not ping, not the test
            // levers - because the command channel is the only way in for all of them.
            new CommandServer(
                Hilos::$env[EnvConstants::COMMAND_HOST],
                Hilos::$env->int(EnvConstants::COMMAND_PORT),
            ),
        ];
    }

    /**
     * The tasks HTTP routes: the shared status endpoint.
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
     * The tasks modules: build-timestamp exposure for the handshake welcome frame.
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
