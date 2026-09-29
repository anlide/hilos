<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Core\Daemon;

use Demo\EcommerceShop\Core\Router\EcommerceShopSignalRouter;
use Demo\EcommerceShop\Core\Socket\Server\EcommerceShopWebSocketServer;
use Demo\EcommerceShop\Core\Socket\Server\EcommerceShopWorkerServer;
use Demo\EcommerceShop\Hilos;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Daemon\DaemonContext;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Daemon\Module\BuildTimestampModule;
use Hilos\Core\Daemon\Module\DaemonModule;
use Hilos\Core\Router\SignalRouter;
use Hilos\Environment\Exception\EnvException;
use Hilos\Socket\Server\CommandServer;
use Hilos\Socket\Server\HttpServer;
use Hilos\Socket\Server\ServerInterface;

/**
 * EcommerceShopDaemonManager - Main daemon manager for the ecommerce-shop demo.
 *
 * Extends framework DaemonManager: declares the ecommerce-shop server set (HTTP status, worker,
 * WebSocket) and the build-timestamp module. No cron rules yet.
 */
final class EcommerceShopDaemonManager extends DaemonManager
{
    /** @var EcommerceShopWorkerServer Worker server, held while composing so the server set below names the built instance */
    private EcommerceShopWorkerServer $workerServer;

    /**
     * Create signal router instance.
     *
     * @return SignalRouter EcommerceShop signal router instance
     */
    protected function createSignalRouter(): SignalRouter
    {
        return new EcommerceShopSignalRouter();
    }

    /**
     * Create agent manager daemon instance.
     *
     * @return AgentManagerDaemon EcommerceShop agent manager daemon instance
     */
    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new EcommerceShopAgentManagerDaemon();
    }

    /**
     * The ecommerce-shop server set: HTTP status, worker, and WebSocket servers.
     *
     * @param DaemonContext $context Resolved path context
     * @return iterable<ServerInterface> Servers to register, in bind order
     * @throws EnvException When a server host/port env value cannot be read
     */
    protected function createServers(DaemonContext $context): iterable
    {
        $this->workerServer = new EcommerceShopWorkerServer(
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
            new EcommerceShopWebSocketServer(
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
     * The ecommerce-shop modules: build-timestamp exposure for the handshake welcome frame.
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
