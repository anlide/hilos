<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Daemon\ContainedFailure;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Daemon\MasterHeartbeat;
use Hilos\Core\Exception\ProcessException;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\EventLoop\EventLoopTimer;
use Hilos\Core\EventLoop\Exception\EventLoopException;
use Hilos\Core\Router\SignalRouter;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Socket\Server\ServerInterface;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Throwable;

final class DaemonManagerHeartbeatTest extends TestCase
{
    private EventLoop $loop;
    private HeartbeatPassManager $manager;
    private MasterHeartbeat $heartbeat;
    private Closure $restore;
    private string $logFile;
    /** @var resource Local reader of the actual heartbeat bytes */
    private mixed $reader;

    public function testOnlyASuccessfullyCompletedGuardedPassWritesAMark(): void
    {
        self::assertTrue($this->invoke('hasRunnableMasterWork'), 'initial due must precede the first blocking wait');
        $this->invoke('runGuardedIteration', microtime(true));
        self::assertSame("\x01", stream_get_contents($this->reader));
        self::assertSame([], $this->manager->failures);

        new ReflectionProperty(MasterHeartbeat::class, 'nextMarkAt')->setValue($this->heartbeat, 0.0);
        $this->manager->failPass = true;
        $this->invoke('runGuardedIteration', microtime(true));
        self::assertSame('', stream_get_contents($this->reader));
        self::assertCount(1, $this->manager->failures);
        self::assertSame(0.0, $this->heartbeat->nextMarkAt());
    }

    public function testFailedWriterRetiresItsTimerAndDueWorkWhileServersDrain(): void
    {
        $timer = new ReflectionProperty(DaemonManager::class, 'masterHeartbeatTimer')->getValue($this->manager);
        $timer->armAt(hrtime(true) / 1e9 + 60.0);
        fclose(new ReflectionProperty(MasterHeartbeat::class, 'pipe')->getValue($this->heartbeat));

        $this->assertHeartbeatFailureRetiresDuringDrain($timer, ProcessException::class);
    }

    public function testFailedTimerArmingAlsoRetiresTheHeartbeatWriterDuringDrain(): void
    {
        $timer = new ReflectionProperty(DaemonManager::class, 'masterHeartbeatTimer')->getValue($this->manager);
        $timer->close();

        $this->assertHeartbeatFailureRetiresDuringDrain($timer, EventLoopException::class);
    }

    public function testNativeTimerOnlyMarksDueAndUnrelatedIoDoesNotMoveTheDeadline(): void
    {
        $this->invoke('runGuardedIteration', microtime(true));
        self::assertSame("\x01", stream_get_contents($this->reader));
        $deadline = $this->heartbeat->nextMarkAt();
        $timer = new ReflectionProperty(DaemonManager::class, 'masterHeartbeatTimer')->getValue($this->manager);
        self::assertInstanceOf(EventLoopTimer::class, $timer);
        self::assertSame($deadline, $timer->getDeadline());

        [$readable, $writer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        try {
            $this->loop->registerRead($readable, static function () use ($readable): void {
                fread($readable, 1);
            });
            fwrite($writer, 'x');
            $this->invoke('runGuardedIteration', microtime(true));
            self::assertSame('', stream_get_contents($this->reader));
            self::assertSame($deadline, $timer->getDeadline());

            new ReflectionProperty(MasterHeartbeat::class, 'nextMarkAt')->setValue($this->heartbeat, 0.0);
            $timer->armAt(0.0);
            $this->loop->tick();
            self::assertTrue(new ReflectionProperty(DaemonManager::class, 'masterHeartbeatDue')->getValue($this->manager));
            self::assertSame('', stream_get_contents($this->reader), 'native firing cannot write a completed-pass mark');
            $this->invoke('runGuardedIteration', microtime(true));
            self::assertSame("\x01", stream_get_contents($this->reader));
            $this->invoke('detachMasterHeartbeat');
            self::assertNull($this->heartbeat->nextMarkAt());
            self::assertNull($timer->getDeadline());
        } finally {
            $this->loop->unregister($readable);
            fclose($readable);
            fclose($writer);
        }
    }

    protected function setUp(): void
    {
        $cluster = Hilos::$cluster;
        $router = Hilos::$sr;
        $env = Hilos::$env;
        $runtime = Hilos::$rt;
        $app = Hilos::appClass();
        $enabled = getenv(EnvConstants::CLUSTER_ENABLED->name);
        $this->restore = static function () use ($cluster, $router, $env, $runtime, $app, $enabled): void {
            Hilos::$cluster = $cluster;
            Hilos::$sr = $router;
            Hilos::$env = $env;
            Hilos::$rt = $runtime;
            new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $app);
            putenv(EnvConstants::CLUSTER_ENABLED->name . ($enabled === false ? '' : '=' . $enabled));
        };
        putenv(EnvConstants::CLUSTER_ENABLED->name . '=false');
        Hilos::$cluster = null;
        Hilos::$rt = null;
        Hilos::$env = new EnvAccessor();
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, Hilos::class);
        $this->logFile = tempnam(sys_get_temp_dir(), 'heartbeat-pass-');
        Logger::setLogFile($this->logFile);
        $this->loop = new EventLoop();
        $this->manager = new HeartbeatPassManager();
        new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($this->manager, $this->loop);
        [$this->reader, $writer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        stream_set_blocking($this->reader, false);
        $this->heartbeat = new MasterHeartbeat($writer, 0.0);
        new ReflectionProperty(DaemonManager::class, 'masterHeartbeat')->setValue($this->manager, $this->heartbeat);
        $this->invoke('attachMasterHeartbeat');
    }

    protected function tearDown(): void
    {
        $this->invoke('detachMasterHeartbeat');
        $this->invoke('retireNativeOwners');
        $this->loop->close();
        fclose($this->reader);
        Logger::resetLogFile();
        unlink($this->logFile);
        ($this->restore)();
        parent::tearDown();
    }

    /**
     * @param EventLoopTimer $timer Exact failed source's timer
     * @param class-string<Throwable> $exception Original failure expected at the common guard
     */
    private function assertHeartbeatFailureRetiresDuringDrain(EventLoopTimer $timer, string $exception): void
    {
        $server = $this->createMock(ServerInterface::class);
        $server->method('getServerName')->willReturn('heartbeat-drain');
        $server->method('isRunning')->willReturn(true);
        $server->method('isReadyToShutdown')->willReturn(false);
        $server->expects(self::once())->method('prepareShutdown');
        $this->manager->registerServer($server);
        $this->invoke('attachProtectedModeWatchdog');

        $this->invoke('runGuardedIteration', microtime(true));
        $failure = $this->manager->failures[0]->failure;
        self::assertInstanceOf($exception, $failure);
        for ($pass = 0; $pass < 2; ++$pass) {
            self::assertTrue($this->invoke('shouldContinueRunning'), 'the server still needs graceful drain');
            $this->invoke('runGuardedIteration', microtime(true));
        }

        self::assertCount(1, $this->manager->failures, 'a failed heartbeat generation is contained only once');
        self::assertSame($failure, $this->manager->failures[0]->failure);
        self::assertSame(1, substr_count(file_get_contents($this->logFile), 'Failure in the daemon loop:'));
        self::assertNull(new ReflectionProperty(DaemonManager::class, 'masterHeartbeat')->getValue($this->manager));
        self::assertNull(new ReflectionProperty(DaemonManager::class, 'masterHeartbeatTimer')->getValue($this->manager));
        self::assertFalse(new ReflectionProperty(DaemonManager::class, 'masterHeartbeatDue')->getValue($this->manager));
        self::assertNull($timer->getDeadline());
        self::assertNull($this->heartbeat->nextMarkAt());
        self::assertFalse($this->invoke('hasRunnableMasterWork'), 'the retired heartbeat cannot spin the drain');
    }

    /**
     * @param string $method Actual guarded phase or lifecycle seam
     * @param mixed ...$arguments Phase arguments
     * @return mixed Actual phase result
     */
    private function invoke(string $method, mixed ...$arguments): mixed
    {
        return new ReflectionMethod(DaemonManager::class, $method)->invoke($this->manager, ...$arguments);
    }
}

final class HeartbeatPassManager extends DaemonManager
{
    public bool $failPass = false;
    /** @var list<ContainedFailure> Failures recorded by the real enclosing guard */
    public array $failures = [];

    /** @return SignalRouter Empty router; no application signal is required */
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    /** @return AgentManagerDaemon Agent factory unused by this master-only fixture */
    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new HeartbeatPassAgentManager();
    }

    /** Fails inside the actual pass when requested. */
    protected function onTickStandalone(): void
    {
        if ($this->failPass) {
            throw new RuntimeException('unfinished heartbeat pass');
        }
    }

    /** @param ContainedFailure $failure Failure reported by the existing guard */
    protected function onContainedFailure(ContainedFailure $failure): void
    {
        $this->failures[] = $failure;
    }
}

final class HeartbeatPassAgentManager extends AgentManagerDaemon
{
    /**
     * @param string $agentType Unused fixture type
     * @param ?string $agentIndex Unused fixture address
     * @return AgentDaemonInterface No agents belong to this fixture
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new AgentDaemonCreationFailedException('No agent belongs to the heartbeat fixture.');
    }
}
