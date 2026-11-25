<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Core\Daemon;

use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Core\Router\EcommerceShopSignalRouter;
use Demo\EcommerceShop\Core\Socket\Server\EcommerceShopWebSocketServer;
use Demo\EcommerceShop\Core\Socket\Server\EcommerceShopWorkerServer;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Pages\CartPage;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Daemon\DaemonContext;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Daemon\Module\BuildTimestampModule;
use Hilos\Core\Daemon\Module\DaemonModule;
use Hilos\Core\Daemon\Module\PeerModule;
use Hilos\Core\Daemon\SignalDispatch;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Environment\Exception\EnvException;
use Hilos\Socket\Server\CommandServer;
use Hilos\Socket\Server\HttpServer;
use Hilos\Socket\Server\ServerInterface;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Utils\Logger;
use Throwable;

/**
 * EcommerceShopDaemonManager - Main daemon manager for the ecommerce-shop demo.
 *
 * Extends framework DaemonManager: declares the ecommerce-shop server set (HTTP status, worker,
 * WebSocket), the cluster peer transport and the build-timestamp module. No cron rules yet.
 */
final class EcommerceShopDaemonManager extends DaemonManager
{
    /** @var EcommerceShopWorkerServer Worker server, held while composing so the server set below names the built instance */
    private EcommerceShopWorkerServer $workerServer;

    /**
     * Replies to a tracked Remove that the router could not address to a cart owner.
     *
     * @param SignalDispatch $dispatch Original routed lifetime and captured browser transport
     * @param bool $hasDestination Whether the router produced a target
     * @throws InvalidArgumentException When a refusal cannot be named
     * @throws InvalidFormatException When subscription recovery configuration is invalid
     */
    public function finishUnservedSignal(SignalDispatch $dispatch, bool $hasDestination): void
    {
        parent::finishUnservedSignal($dispatch, $hasDestination);
        if ($hasDestination) {
            return;
        }

        $signal = $dispatch->getSignal();
        if ($signal->signalSource->getSource() !== SignalSource::WEBSOCKET
            || $signal->signalType->getType() !== SignalTypeConstants::ACTION
            || $signal->signalName->getName() !== EcommerceShopSignalConstants::CART_REMOVE_VOUCHER
            || !$signal->data instanceof WebSocketActionSignalDTO
            || $signal->data->requestId === null
        ) {
            return;
        }

        // The captured target protects the original socket's lifetime; its queue owns the subscription hold.
        $client = $dispatch->clientTarget?->getClient();
        if ($client === null) {
            return;
        }

        $frame = $this->prepareSignalFrame(new SignalDTO(
            new SignalSource(SignalSource::DAEMON),
            new SignalType(SignalTypeConstants::WS_USER),
            new SignalName(SignalConstants::ACTION_ERROR),
            new PageActionErrorSignalData(
                EcommerceShopSignalConstants::CART_REMOVE_VOUCHER,
                CartPage::CART_UNAVAILABLE,
                $signal->data->requestId,
            ),
        ));
        if ($frame === null) {
            return;
        }
        try {
            $client->sendDeliveryFrame($frame);
        } catch (Throwable $failure) {
            Logger::error('Failed to queue cart voucher removal refusal: ' . $failure->getMessage());
        }
    }

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
     * The ecommerce-shop modules: the cluster peer transport, which binds nothing unless the node
     * is in a cluster - this demo's cluster stand (docker/docker-compose.cluster.yml) is the one
     * that puts it in one - and build-timestamp exposure for the handshake welcome frame.
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
