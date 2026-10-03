<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Router\SignalRouter;
use Hilos\Socket\Client\Interface\WebSocketClientInterface;
use Hilos\Socket\Server\WebSocketServer;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\SocketException;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/** The master closes browser admission first and releases existing browsers last (HIL-1207). */
final class DaemonManagerShutdownOrderTest extends TestCase
{
    private string $logFile = '';

    protected function setUp(): void
    {
        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-shutdown-order');
        Logger::setLogFile($this->logFile);
    }

    protected function tearDown(): void
    {
        Logger::resetLogFile();
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }

        parent::tearDown();
    }

    public function testRequestedExitClosesBrowserEntranceInItsFirstPass(): void
    {
        $manager = new ShutdownOrderTestManager();
        $browsers = new ShutdownOrderTestWebSocketServer();
        $manager->registerServer($browsers);
        $browsers->start();
        $manager->handleShutdown();

        new ReflectionMethod(DaemonManager::class, 'runGuardedIteration')->invoke($manager, microtime(true));

        $this->assertFalse($browsers->isRunning());
        $this->assertSame(1, $browsers->stopCount);
    }

    public function testBrowsersAreReleasedOnceOnlyAfterWorkerConnectionsLeave(): void
    {
        $manager = new ShutdownOrderTestManager();
        $workers = new ShutdownOrderTestWorkerServer();
        $browsers = new ShutdownOrderTestWebSocketServer();
        $manager->registerServer($workers);
        $manager->registerServer($browsers);
        new ReflectionProperty(DaemonManager::class, 'shutdownStartTime')->setValue($manager, microtime(true));
        $release = new ReflectionMethod(DaemonManager::class, 'releaseBrowsersOnceWorkersLeft');

        $release->invoke($manager);
        $this->assertSame(0, $browsers->releaseCount);

        $workers->ready = true;
        $release->invoke($manager);
        $release->invoke($manager);
        $this->assertSame(1, $browsers->releaseCount);
    }

    public function testReadinessDoesNotReopenTheEntranceDuringShutdown(): void
    {
        $manager = new ShutdownOrderTestManager();
        $browsers = new ShutdownOrderTestWebSocketServer();
        $manager->registerServer($browsers);
        new ReflectionProperty(DaemonManager::class, 'workersReady')->setValue($manager, true);
        new ReflectionProperty(DaemonManager::class, 'shutdownStartTime')->setValue($manager, microtime(true));

        new ReflectionMethod(DaemonManager::class, 'tickReadiness')->invoke($manager);

        $this->assertSame(0, $browsers->startCount);
    }
}

/** Manager that ends a guarded pass immediately after the shutdown entry step. */
final class ShutdownOrderTestManager extends DaemonManager
{
    /** @throws RuntimeException To end the pass after its shutdown entry step */
    protected function processEventLoop(): void
    {
        throw new RuntimeException('stop after shutdown entry');
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new ShutdownOrderTestAgentManagerDaemon();
    }
}

/** Worker readiness controlled by the test, without a process. */
final class ShutdownOrderTestWorkerServer extends WorkerServer
{
    public bool $ready = false;

    public function __construct()
    {
    }

    public function prepareShutdown(): void
    {
    }

    /** @return bool Whether all worker connections have left */
    public function isReadyToShutdown(): bool
    {
        return $this->ready;
    }

    protected function onStart(): void
    {
    }
}

/** Browser server that records entrance closing and final release. */
final class ShutdownOrderTestWebSocketServer extends WebSocketServer
{
    public int $startCount = 0;

    public int $stopCount = 0;

    public int $releaseCount = 0;

    public function __construct()
    {
        parent::__construct('127.0.0.1', 0);
    }

    /** @return bool True after opening the fake listener */
    public function start(): bool
    {
        $this->startCount++;
        $this->isRunning = true;
        return true;
    }

    public function stop(): void
    {
        $this->stopCount++;
        $this->isRunning = false;
    }

    public function closeClientsOnceWritten(): void
    {
        $this->releaseCount++;
    }

    protected function onStart(): void
    {
    }

    /**
     * @param resource $socket Unused accepted socket
     * @return WebSocketClientInterface Never returned
     * @throws SocketException Always, since this test does not accept clients
     */
    protected function onCreateClient($socket): WebSocketClientInterface
    {
        throw new SocketException('the shutdown order test accepts no clients');
    }
}

/** Agent manager whose factory is never called. */
final class ShutdownOrderTestAgentManagerDaemon extends AgentManagerDaemon
{
    /**
     * @param string $agentType Unused type
     * @param ?string $agentIndex Unused index
     * @return AgentDaemonInterface Never returned
     * @throws AgentDaemonCreationFailedException Always
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new AgentDaemonCreationFailedException('the shutdown order test starts no agent');
    }
}
