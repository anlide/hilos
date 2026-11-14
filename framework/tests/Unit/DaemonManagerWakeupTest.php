<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Event;
use EventBase;
use Generator;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\PeerLink;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Cluster\Peer\DTO\PeerDbSyncDTO;
use Hilos\Cluster\Peer\DTO\PeerRtSnapshotDTO;
use Hilos\Cluster\Peer\DTO\PeerRtReplicaOfferDTO;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Daemon\ContainedFailure;
use Hilos\Core\Daemon\DaemonDeparture;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Daemon\SignalDispatchPhase;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\EventLoop\Exception\EventLoopPollException;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\Destination\AgentAddressedDestination;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Runtime\Stream\RtSyncStreamOperation;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\State\Collection\RtStates;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Socket\Server\ServerInterface;
use Hilos\Utils\Logger;
use Hilos\Tests\Unit\Cluster\Peer\PeerTestTls;
use Hilos\Tests\Unit\Cluster\Peer\PeerTestMarkers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Socket;
use WeakReference;

/** Real native I/O and guarded outcomes; no child process or production timer wait. */
final class DaemonManagerWakeupTest extends TestCase
{
    private EventLoop $loop;
    private WakeupManager $manager;
    private string $log;
    private string|false $enabled;
    /** @var array<string, mixed> Facade values restored after every native lifetime */
    private array $previous = [];
    /** @var list<Socket> Real I/O ends, closed after loop retirement */
    private array $sockets = [];

    public function testIdleWaitReadsIoAndKeepsTheFutureRoleDeadline(): void
    {
        $this->guard();
        $this->assertFalse($this->runnable());
        $future = hrtime(true) / 1e9 + 60;
        $this->manager->arm($future);
        $seen = [];
        $this->readyIo(function () use (&$seen): void { $seen[] = 'read'; });
        $this->guard();
        $this->assertSame(['read'], $seen);
        $this->assertSame(2, $this->manager->hooks);
        $this->assertSame($future, $this->get('roleTickDeadline'));
        $this->assertFalse($this->runnable());
    }

    public function testFiniteSourceContinuationFinishesWithoutAnotherIoFrame(): void
    {
        $this->guard();
        $seen = [];
        $this->invoke('queueSignalEffect', static function () use (&$seen): Generator {
            for ($step = 0; $step < 2000; ++$step) {
                $seen[] = $step;
                yield;
            }
        });
        $passes = 0;
        while ($this->runnable() && ++$passes < 100) {
            $this->guard();
        }
        $this->assertSame(range(0, 1999), $seen);
        $this->assertGreaterThan(1, $passes);
        $this->assertLessThan(100, $passes);
        $this->assertFalse($this->runnable());
        $this->readyIo(static function (): void {});
        $this->guard();
    }

    /** @return iterable<string, array{int, string}> Native controls and their original lifecycle hook */
    public static function controls(): iterable
    {
        yield 'TERM' => [SIGTERM, 'stop'];
        yield 'INT' => [SIGINT, 'stop'];
        yield 'HUP' => [SIGHUP, 'restart'];
    }

    /**
     * @param int $signal Native control sent to this process
     * @param string $hook Expected lifecycle result
     */
    #[DataProvider('controls')]
    public function testNativeControlsDeferLifecycleUntilTheGuard(int $signal, string $hook): void
    {
        pcntl_sigprocmask(SIG_BLOCK, [SIGUSR1], $before);
        pcntl_sigprocmask(SIG_SETMASK, $before);
        $this->manager->installControls();
        pcntl_sigprocmask(SIG_BLOCK, [SIGUSR1], $after);
        pcntl_sigprocmask(SIG_SETMASK, $after);
        $this->assertSame($before, $after, 'worker spawn inherits the original mask');
        $this->assertTrue(posix_kill(getmypid(), $signal));
        $this->loop->runOnce();
        $this->assertSame([], $this->manager->controls);
        $this->assertFalse($this->manager->exiting());
        $this->guard();
        $this->assertSame([$hook], $this->manager->controls);
        $this->assertTrue($this->manager->exiting());
        $this->assertSame(DaemonDeparture::Stopped, $this->manager->departure());
        $this->assertSame(1, $this->manager->hooks);
    }

    public function testControlRetirementPreservesTheWorkerChildReceiver(): void
    {
        $children = 0;
        $this->loop->registerSignal(SIGCHLD, static function () use (&$children): void { ++$children; });
        $this->manager->installControls();
        $this->invoke('detachMasterProcessSignals');
        $this->assertTrue(posix_kill(getmypid(), SIGCHLD));
        $this->loop->runOnce();
        $this->assertSame(1, $children);
        $this->assertSame([], $this->manager->controls);
    }

    public function testThrowingRoleRetiresOnceWhileHealthyDrainContinues(): void
    {
        $this->guard();
        $prepare = 0;
        $server = $this->createMock(ServerInterface::class);
        $server->method('getServerName')->willReturn('draining');
        $server->method('isRunning')->willReturn(true);
        $server->method('isReadyToShutdown')->willReturn(false);
        $server->method('prepareShutdown')->willReturnCallback(static function () use (&$prepare): void { ++$prepare; });
        $this->manager->registerServer($server);
        $this->manager->onHook = static function (): void { throw new RuntimeException('role refused'); };
        $this->manager->arm(hrtime(true) / 1e9 - 1);
        $this->guard();
        $this->assertSame(1, count($this->manager->failures));
        $this->assertSame([false], $this->manager->exitAtFailure);
        $delivered = 0;
        for ($pass = 0; $pass < 3; ++$pass) {
            $this->readyIo(static function () use (&$delivered): void { ++$delivered; });
            $this->guard();
        }
        $this->assertSame(3, $delivered);
        $this->assertSame(1, $prepare);
        $this->assertSame(2, $this->manager->hooks);
        $this->assertSame(1, count($this->manager->failures));
        $this->assertSame(DaemonDeparture::Failed, $this->manager->departure());
        $this->assertFalse($this->runnable(), 'requested exit during a healthy drain does not spin');
    }

    public function testBrokenNativeBasePreparesOnceAndEndsWithoutPollingAgain(): void
    {
        $this->guard();
        $prepare = 0;
        $server = $this->createMock(ServerInterface::class);
        $server->method('getServerName')->willReturn('unreachable-drain');
        $server->method('isRunning')->willReturn(true);
        $server->method('isReadyToShutdown')->willReturn(false);
        $server->method('prepareShutdown')->willReturnCallback(static function () use (&$prepare): void { ++$prepare; });
        $this->manager->registerServer($server);
        // A reentrant native poll is a real libevent refusal, without destroying a base
        // underneath its registered owners or replacing any native class.
        $event = Event::timer($this->loop->getEventBase(), function (): void { $this->guard(); });
        $event->add(0.0);
        set_error_handler([$this->manager, 'errorHandler']);
        try {
            $this->loop->getEventBase()->loop(EventBase::LOOP_ONCE);
        } finally {
            restore_error_handler();
            $event->del();
            $event->free();
        }
        $this->assertInstanceOf(EventLoopPollException::class, $this->manager->failures[0]->failure);
        $this->assertSame([false], $this->manager->exitAtFailure);
        $this->guard();
        $this->assertSame(1, $prepare);
        $this->assertFalse($this->invoke('shouldContinueRunning'));
        $this->assertSame(1, $this->manager->hooks);
        $this->assertCount(1, $this->manager->failures);
        $this->assertSame(DaemonDeparture::Failed, $this->manager->departure());
        $this->invoke('retireNativeOwners');
        $this->assertSame(0, $this->loop->registeredCount());
        $this->assertFalse($this->loop->hasReadyWork());
    }

    public function testShutdownCeilingKeepsItsMonotonicBasisAcrossWallChanges(): void
    {
        $this->guard();
        $server = $this->createMock(ServerInterface::class);
        $server->method('getServerName')->willReturn('waiting');
        $server->method('isRunning')->willReturn(true);
        $server->method('isReadyToShutdown')->willReturn(false);
        $this->manager->registerServer($server);
        $this->manager->handleShutdown();
        $this->readyIo(static function (): void {});
        $this->guard();
        $deadline = $this->get('shutdownDeadline');
        foreach ([-3600, 3600] as $jump) {
            $this->set('shutdownStartTime', microtime(true) + $jump);
            $this->assertTrue($this->invoke('shouldContinueRunning'));
            $this->assertSame($deadline, $this->get('shutdownDeadline'));
        }
        $this->set('shutdownDeadline', hrtime(true) / 1e9 - 1);
        $this->assertFalse($this->invoke('shouldContinueRunning'));
        $this->assertSame(DaemonDeparture::Stopped, $this->manager->departure());
    }

    public function testRealRunWithoutServersPreparesAndReturnsBeforeAnotherNativeWait(): void
    {
        foreach ([true, false] as $requestedBeforeRun) {
            $manager = new WakeupManager();
            if ($requestedBeforeRun) {
                $manager->handleShutdown();
            } else {
                $manager->onHook = static function () use ($manager): void {
                    $manager->onHook = null;
                    $manager->handleShutdown();
                };
            }
            $this->assertSame(DaemonDeparture::Stopped, $manager->run());
            $this->assertSame($requestedBeforeRun ? 1 : 2, $manager->hooks);
            $loop = new ReflectionProperty(DaemonManager::class, 'eventLoop')->getValue($manager);
            $this->assertSame(0, $loop->registeredCount());
            $this->assertFalse($loop->hasReadyWork());
        }
    }

    /**
     * @param string $door Original peer apply context retained beyond the peer callback
     * @param bool $metadataFailure Whether retirement of an original destination also fails
     */
    #[TestWith(['RT sync'])]
    #[TestWith(['DB sync'])]
    #[TestWith(['RT snapshot'])]
    #[TestWith(['RT replica offer'])]
    #[TestWith(['RT snapshot', true])]
    public function testDeferredPeerApplyFailureStaysInsideItsOriginalGuard(string $door, bool $metadataFailure = false): void
    {
        $this->guard();
        $this->invoke('attachSignalDispatchEventLoop');
        Hilos::$rt = new WakeupPeerRtContext();
        Hilos::$rt->configure();
        Hilos::$cluster = new ClusterContext();
        Hilos::$cluster->registerRtSyncSink($this->manager);
        Hilos::$cluster->registerDbSyncSink($this->manager);
        $peer = new PeerServer('127.0.0.1', 0, NodeIdentity::of('node-a', 'Node A', NodeRole::Master, []),
            [], PeerTestTls::unread(), PeerTestMarkers::shared());
        $link = new ReflectionClass(PeerLink::class)->newInstanceWithoutConstructor();
        new ReflectionProperty(PeerLink::class, 'remoteIdentity')->setValue($link, NodeIdentity::of('node-b', 'Node B', NodeRole::Master, []));
        $head = 0;
        $this->invoke('queueSignalEffect', static function () use (&$head): Generator {
            yield;
            for ($step = 0; $step < 512; $step++) {
                $head++;
                yield;
            }
        });
        $key = DaemonManagerRtSyncPeerTestRtContext::ROWS;
        if ($door === 'RT sync') {
            $peer->onRtSyncStreamReceived($link, RtSyncStreamOperation::fromSignalData(
                new RtSyncCreatedSignalData($key, '7', ['id' => '7', 'name' => 'bad']), 1, 'node-b',
            ), static function (): void {
            });
        } elseif ($door === 'DB sync') {
            $signal = new SignalDTO(new SignalSource(SignalSource::DB), new SignalType(SignalTypeConstants::DB_SYNC_CREATED),
                new SignalName(SignalConstants::DB_SYNC_CREATED), new RtSyncCreatedSignalData($key, '7', ['id' => '7']));
            $frame = new PeerDbSyncDTO('node-b', SignalTypeConstants::DB_SYNC_CREATED, $signal);
            $peer->onDbSyncReceived($link, PeerDbSyncDTO::fromArray($frame->toArray()));
        } elseif ($door === 'RT snapshot') {
            $frame = new PeerRtSnapshotDTO('node-b', $key, ['7' => ['id' => '7', 'name' => 'bad']]);
            $peer->onRtSnapshotReceived($link, PeerRtSnapshotDTO::fromArray($frame->toArray()));
        } else {
            $frame = new PeerRtReplicaOfferDTO('node-b', $key, ['7' => ['id' => '7', 'name' => 'bad']]);
            $peer->onRtReplicaOfferReceived($link, PeerRtReplicaOfferDTO::fromArray($frame->toArray()));
        }
        $state = $this->invoke('signalDispatchState');
        $failed = $state->effects[array_key_last($state->effects)];
        $prefix = $state->browsers = $this->invoke('newSignalDispatchPrefix');
        $disposals = 0;
        $failed->physical = (static function () use (&$disposals): Generator {
            try {
                yield;
            } finally {
                $disposals++;
                throw new RuntimeException('secondary physical disposal');
            }
        })();
        $failed->physical->valid();
        $metadataDisposals = 0;
        $metadata = null;
        if ($metadataFailure) {
            $destination = new class (
                static function () use (&$metadataDisposals): void { $metadataDisposals++; },
            ) implements AgentAddressedDestination {
                public string $agentType = 'cleanup';
                public ?string $agentIndex = null;

                /** @param Closure(): void $dispose Count the one original metadata disposal */
                public function __construct(private readonly Closure $dispose)
                {
                }

                /** Fail only when the guarded metadata effect releases this original destination. */
                public function __destruct()
                {
                    ($this->dispose)();
                    throw new RuntimeException('metadata cleanup failed');
                }
            };
            $metadata = WeakReference::create($destination);
            $failed->agentsDelivered[] = $destination;
            unset($destination);
        }
        $peer->onRtSyncStreamReceived($link, RtSyncStreamOperation::fromSignalData(
            new RtSyncCreatedSignalData($key, '8', ['id' => '8', 'name' => 'healthy']), 2, 'node-b',
        ), static function (): void {
        });
        self::assertLessThan(512, $head);
        $this->loop->tick();
        self::assertNull(Hilos::$rt->rows->get('8'), 'Native callbacks only cue owner work');
        for ($portion = 0; $portion < 64 && $this->runnable(); $portion++) {
            $this->guard();
        }
        self::assertFalse($this->manager->exiting());
        self::assertSame([], $this->manager->failures);
        self::assertSame(SignalDispatchPhase::Failed, $failed->phase);
        self::assertFalse($failed->hasWork());
        self::assertTrue($prefix->failed, 'Containment cannot turn a failed prefix into success');
        self::assertFalse($prefix->complete);
        self::assertSame('healthy', Hilos::$rt->rows->get('8')?->name);
        self::assertSame(1, $disposals);
        self::assertSame($metadataFailure ? 1 : 0, $metadataDisposals);
        self::assertNull($metadata?->get());
        self::assertSame([], $state->effects, 'Neither a failed apply nor its failed cleanup is retried');
        $log = file_get_contents($this->log);
        self::assertStringNotContainsString('secondary physical disposal', $log, 'The apply failure remains primary');
        self::assertSame($metadataFailure ? 2 : 1, substr_count($log, "Failed to apply peer {$door} from node 'node-b':"));
    }

    /** Real readable bytes make a blocking poll deterministic without any clock wait. */
    private function readyIo(Closure $seen): void
    {
        $pair = [];
        $this->assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        foreach ($pair as $socket) {
            socket_set_nonblock($socket);
            $this->sockets[] = $socket;
        }
        $this->loop->registerRead($pair[0], function () use ($pair, $seen): void {
            socket_read($pair[0], 1);
            $this->loop->unregisterRead($pair[0]);
            $seen();
        });
        socket_write($pair[1], 'x');
    }

    private function guard(): void
    {
        $this->invoke('runGuardedIteration', microtime(true));
    }

    /** @return bool Composed source verdict, rather than just native FIFO membership */
    private function runnable(): bool
    {
        return $this->invoke('hasRunnableMasterWork');
    }

    /**
     * @param string $method Existing private integration seam
     * @param mixed ...$args Method arguments
     * @return mixed Original method result
     */
    private function invoke(string $method, mixed ...$args): mixed
    {
        return new ReflectionMethod(DaemonManager::class, $method)->invoke($this->manager, ...$args);
    }

    /**
     * @param string $field Process-local driver field
     * @return mixed Current value
     */
    private function get(string $field): mixed
    {
        return new ReflectionProperty(DaemonManager::class, $field)->getValue($this->manager);
    }

    /**
     * @param string $field Process-local driver field
     * @param mixed $value Explicit fixture basis
     */
    private function set(string $field, mixed $value): void
    {
        new ReflectionProperty(DaemonManager::class, $field)->setValue($this->manager, $value);
    }

    protected function setUp(): void
    {
        foreach (['cluster', 'rt', 'sr', 'env', 'ac'] as $field) {
            $this->previous[$field] = Hilos::${$field};
        }
        $this->previous['app'] = Hilos::appClass();
        $this->enabled = getenv(EnvConstants::CLUSTER_ENABLED->name);
        putenv(EnvConstants::CLUSTER_ENABLED->name . '=false');
        Hilos::$cluster = null;
        Hilos::$rt = null;
        Hilos::$ac = null;
        Hilos::$env = new EnvAccessor();
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, Hilos::class);
        $this->log = (string)tempnam(sys_get_temp_dir(), 'hilos-wakeup-');
        Logger::setLogFile($this->log);
        $this->loop = new EventLoop();
        $this->manager = new WakeupManager();
        $this->set('eventLoop', $this->loop);
        $this->set('nativeWaitEnabled', true);
        $this->invoke('attachRoleTickEventLoop');
        $this->invoke('attachProtectedModeWatchdog');
    }

    protected function tearDown(): void
    {
        $this->invoke('retireNativeOwners');
        foreach ($this->sockets as $socket) {
            socket_close($socket);
        }
        Logger::resetLogFile();
        unlink($this->log);
        foreach (['cluster', 'rt', 'sr', 'env', 'ac'] as $field) {
            Hilos::${$field} = $this->previous[$field];
        }
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->previous['app']);
        putenv(EnvConstants::CLUSTER_ENABLED->name . ($this->enabled === false ? '' : '=' . $this->enabled));
        parent::tearDown();
    }
}

/** Real runtime mutation with one failing project collection hook. */
final class WakeupPeerRtContext extends RtContext
{
    public RtStates $rows;

    /** Mount the collection used by the real peer source apply. */
    public function configure(): void
    {
        $this->rows = new class extends RtStates {
            public const string STATE_CLASS = DaemonManagerRtSyncPeerTestState::class;

            /** Create the mounted test collection without a separate factory class. */
            public function __construct()
            {
                parent::__construct();
            }

            /** @param RtState $state Hydrated remote row; one row simulates an extension failure */
            public function add(RtState $state): void
            {
                if ($state->getId() === '7') {
                    throw new RuntimeException('peer apply collection hook failed');
                }
                parent::add($state);
            }
        };
        $this->_stateCollections[DaemonManagerRtSyncPeerTestRtContext::ROWS] = $this->rows;
    }
}

/** No fixture overrides the native driver or its signal receiver. */
final class WakeupManager extends DaemonManager
{
    public int $hooks = 0;
    public ?Closure $onHook = null;
    /** @var list<string> Original lifecycle hooks */
    public array $controls = [];
    /** @var list<ContainedFailure> Iteration failures */
    public array $failures = [];
    /** @var list<bool> Exit verdict at the failure hook */
    public array $exitAtFailure = [];

    public function installControls(): void
    {
        $this->setupSignalHandlers();
    }

    /** @param ?float $at Explicit original role deadline */
    public function arm(?float $at): void
    {
        $this->armRoleTickAt($at);
    }

    /** @return bool Existing exit request */
    public function exiting(): bool
    {
        return $this->shouldExit;
    }

    /** @return DaemonDeparture Existing departure verdict */
    public function departure(): DaemonDeparture
    {
        return $this->departure;
    }

    /** Native run tests keep the process-wide PHPUnit handlers and bootstrap unchanged. */
    protected function setupErrorHandling(): void
    {
    }

    protected function startServers(): void
    {
    }

    protected function onTickStandalone(): void
    {
        ++$this->hooks;
        $this->onHook?->__invoke();
    }

    protected function onShutdownSignal(): void
    {
        $this->controls[] = 'stop';
    }

    protected function onRestartSignal(): void
    {
        $this->controls[] = 'restart';
    }

    /** @param ContainedFailure $failure Original guard card */
    protected function onContainedFailure(ContainedFailure $failure): void
    {
        $this->failures[] = $failure;
        $this->exitAtFailure[] = $this->shouldExit;
    }

    /** @return SignalRouter Real bounded FIFO */
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    /** @return AgentManagerDaemon Real source owner with no project agents */
    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new WakeupAgents();
    }
}

/** Real reader/ownership bookkeeping without a project factory. */
final class WakeupAgents extends AgentManagerDaemon
{
    /**
     * @param string $agentType Unused type
     * @param ?string $agentIndex Unused index
     * @return AgentDaemonInterface Never returned
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new AgentDaemonCreationFailedException('the wakeup fixture starts no agent');
    }
}
