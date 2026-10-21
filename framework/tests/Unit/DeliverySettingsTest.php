<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalRouter;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\Settings\Validation\PositiveIntegerRule;
use Hilos\Hilos;
use Hilos\Socket\Client\Interface\WebSocketClientInterface;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Server\AbstractServer;
use Hilos\Socket\Server\WebSocketServer;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\WebSocket\Delivery\DeliverySettingsCatalog;
use Hilos\Socket\WebSocket\Delivery\DeliverySettingsMirror;
use Hilos\Socket\Worker\DTO\WorkerDeliverySettingsDTO;
use Hilos\Socket\Worker\DTO\WorkerRegisterDTO;
use Hilos\Socket\Worker\WorkerDTO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

/** Catalog and master mirror remain usable without a database or settings accessor. */
final class DeliverySettingsTest extends TestCase
{
    public function testFrameworkDefaultAndProjectOverrideWithoutDataContexts(): void
    {
        $previous = [Hilos::$db, Hilos::$rt, Hilos::$setting];
        Hilos::$db = null;
        Hilos::$rt = null;
        try {
            Hilos::$setting = new SettingsAccessor();
            self::assertSame(1000, Hilos::$setting[DeliverySettingsCatalog::WINDOW_MS_KEY]->int());
            self::assertTrue(Hilos::$setting->visibleInAdminViewMode(DeliverySettingsCatalog::WINDOW_MS_KEY));
            self::assertSame(PositiveIntegerRule::class, Hilos::$setting->ruleFor(DeliverySettingsCatalog::WINDOW_MS_KEY));
            Hilos::$setting = new SettingsAccessor(DeliverySettingsOverrideCatalog::class);
            self::assertSame(75, Hilos::$setting[DeliverySettingsCatalog::WINDOW_MS_KEY]->int());
        } finally {
            [Hilos::$db, Hilos::$rt, Hilos::$setting] = $previous;
        }
    }

    public function testDefaultMirrorAndRegisteredReportsNeedNoDataContexts(): void
    {
        $previous = [Hilos::$db, Hilos::$rt, Hilos::$setting, Hilos::$cluster];
        Hilos::$db = null;
        Hilos::$rt = null;
        Hilos::$setting = null;
        Hilos::$cluster = null;
        try {
            $mirror = new DeliverySettingsMirror();
            self::assertSame(1000, $mirror->getWindowMs());
            $worker = new DeliverySettingsReceivingClient();
            $worker->setDeliverySettingsSink($mirror);
            $worker->receive(new WorkerRegisterDTO(1));
            $worker->receive(new WorkerDeliverySettingsDTO(200));
            self::assertSame(200, $mirror->getWindowMs());
            try {
                $mirror->onDeliverySettings($worker, -1);
                self::fail('Nonpositive mirror report must fail');
            } catch (InvalidFormatException) {
                self::assertSame(200, $mirror->getWindowMs());
            }
            self::assertNull(Hilos::$db);
            self::assertNull(Hilos::$rt);
            self::assertNull(Hilos::$setting);
        } finally {
            [Hilos::$db, Hilos::$rt, Hilos::$setting, Hilos::$cluster] = $previous;
        }
    }

    public function testAnUnregisteredWorkerCannotChangeTheMirror(): void
    {
        $mirror = new DeliverySettingsMirror();
        $worker = new DeliverySettingsReceivingClient();
        $worker->setDeliverySettingsSink($mirror);
        try {
            $worker->receive(new WorkerDeliverySettingsDTO(20));
            self::fail('Unregistered channel must be refused');
        } catch (InvalidFormatException $failure) {
            self::assertSame('Delivery settings require a registered worker', $failure->getMessage());
            self::assertSame(1000, $mirror->getWindowMs());
        }
    }

    public function testServerRegistrationSharesOneMirrorAndWiresExistingWorkerClients(): void
    {
        $manager = new DeliverySettingsMaster();
        $workers = new DeliverySettingsWorkerServer();
        $webSockets = new DeliverySettingsWebSocketServer('127.0.0.1', 0);
        $worker = new DeliverySettingsReceivingClient();
        new ReflectionProperty(AbstractServer::class, 'clients')->setValue($workers, [$worker]);
        $manager->registerServer($webSockets);
        $manager->registerServer($workers);
        $mirror = new ReflectionProperty(WebSocketServer::class, 'deliverySettingsMirror')->getValue($webSockets);
        self::assertInstanceOf(DeliverySettingsMirror::class, $mirror);
        self::assertSame($mirror, new ReflectionProperty(WorkerServer::class, 'deliverySettingsSink')->getValue($workers));
        $worker->receive(new WorkerRegisterDTO(1));
        $worker->receive(new WorkerDeliverySettingsDTO(80));
        self::assertSame(80, $mirror->getWindowMs());
    }
}

final class DeliverySettingsOverrideCatalog implements CatalogProviderInterface
{
    /** @return array<string, array<string, mixed>> Project override of the framework default */
    public static function getCatalog(): array
    {
        return ['websocket.delivery_window_ms' => ['type' => 'integer', 'default_value' => 75]];
    }
}

/** Exercises the real master-side parser and registration without a socket. */
final class DeliverySettingsReceivingClient extends WorkerClient
{
    public function __construct()
    {
    }

    /** @param WorkerDTO $frame Worker frame passed through the actual parser */
    public function receive(WorkerDTO $frame): void
    {
        $this->readBuffer = $frame->toJson() . "\n";
        $this->processReadBuffer();
    }
}

final class DeliverySettingsMaster extends DaemonManager
{
    public function __construct()
    {
    }

    /** @return SignalRouter Unused in this socket-free fixture */
    protected function createSignalRouter(): SignalRouter
    {
        throw new RuntimeException('Unused factory');
    }

    /** @return AgentManagerDaemon Unused in this socket-free fixture */
    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        throw new RuntimeException('Unused factory');
    }
}

final class DeliverySettingsWorkerServer extends WorkerServer
{
    public function __construct()
    {
    }

    /** No listening socket is opened by this fixture. */
    protected function onStart(): void
    {
    }
}

final class DeliverySettingsWebSocketServer extends WebSocketServer
{
    /** No listening socket is opened by this fixture. */
    protected function onStart(): void
    {
    }

    /**
     * @param resource $socket Unused socket
     * @return WebSocketClientInterface Unused in this registration fixture
     */
    protected function onCreateClient($socket): WebSocketClientInterface
    {
        throw new RuntimeException('Unused factory');
    }
}
