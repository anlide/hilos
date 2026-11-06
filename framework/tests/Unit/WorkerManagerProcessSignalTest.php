<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Event;
use EventBase;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Socket\Worker\DaemonConnectionState;
use Hilos\Socket\Worker\DTO\WorkerRegisterDTO;
use Hilos\Socket\Worker\WorkerDaemonClient;
use Hilos\Socket\Worker\WorkerReadWake;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Socket;

/** Real POSIX delivery and ready libevent events, with no clock-based waiting. */
final class WorkerManagerProcessSignalTest extends TestCase
{
    /**
     * A real signal received before await wakes the socket base and stays marked
     * until ordinary manager code applies its lifecycle action exactly once.
     *
     * @param int $signal Signal sent to this process
     */
    #[DataProvider('processSignals')]
    public function testSignalWakesTheSocketBaseAndLifecycleRunsOutsideCallbacks(int $signal): void
    {
        $this->withManager(function (WorkerProcessSignalManager $manager) use ($signal): void {
            $peer = $this->connectPair($manager->client());
            try {
                $this->assertTrue(posix_kill(posix_getpid(), $signal));

                $this->assertSame(WorkerReadWake::INTERRUPTED, $manager->client()->awaitReadable(600.0));
                $this->assertSame([], $manager->actions);
                $timer = $this->property($manager->client(), 'waitTimer');
                $this->assertInstanceOf(Event::class, $timer);
                $this->assertFalse($timer->pending(Event::TIMEOUT));
                $this->assertSame(WorkerReadWake::INTERRUPTED, $manager->client()->awaitReadable(600.0));

                $manager->pollSignals();
                $manager->pollSignals();

                $this->assertSame([$signal === SIGHUP ? 'restart' : 'shutdown'], $manager->actions);
                $this->assertTrue($manager->exitRequested());
                $this->assertFalse($manager->lifecycleInsideLoop);
                $this->assertSame($signal === SIGHUP ? 1 : 0, Hilos::$env->reloads);
                $this->assertSame($signal !== SIGHUP, $this->workerProperty($manager, 'leavingOnStopSignal'));
                $this->assertSame(1, socket_write($peer, 'x'));
                $this->assertSame(WorkerReadWake::READABLE, $manager->client()->awaitReadable(600.0));
                $this->assertSame($timer, $this->property($manager->client(), 'waitTimer'));
                $this->assertFalse($timer->pending(Event::TIMEOUT));
            } finally {
                socket_close($peer);
            }
        });
    }

    /**
     * The native wait dispatches an OS signal already pending at its boundary,
     * removes its timer, and returns to lifecycle code without entering work.
     *
     * @param int $signal Signal already pending at the wait boundary
     */
    #[DataProvider('processSignals')]
    public function testManagerDoesNotLoseASignalImmediatelyBeforeWaiting(int $signal): void
    {
        $this->withManager(function (WorkerProcessSignalManager $manager) use ($signal): void {
            $peer = $this->connectPair($manager->client());
            try {
                $this->assertTrue(posix_kill(posix_getpid(), $signal));
                $manager->wait(600.0);

                $this->assertTrue($manager->exitRequested());
                $this->assertCount(1, $manager->actions);
                $timer = $this->property($manager->client(), 'waitTimer');
                $this->assertInstanceOf(Event::class, $timer);
                $this->assertFalse($timer->pending(Event::TIMEOUT));
                $this->assertSame(0, $manager->ticks);
            } finally {
                socket_close($peer);
            }
        });
    }

    /**
     * Signal subscriptions exist before connect; run() applies the ordinary stop
     * or environment reload and cleans up without attempting a connection.
     *
     * @param int $signal Signal delivered as installation completes
     */
    #[DataProvider('processSignals')]
    public function testRunHandlesSignalsBeforeConnection(int $signal): void
    {
        $this->withManager(function (WorkerProcessSignalManager $manager) use ($signal): void {
            $manager->signalOnInstall = $signal;
            $manager->run();

            $this->assertFalse($manager->connectAttempted);
            $this->assertTrue($manager->cleanedUp);
            $this->assertTrue($manager->exitRequested());
            $this->assertCount(1, $manager->actions);
            $this->assertSame(0, $manager->ticks);
            $this->assertSame($signal === SIGHUP ? 1 : 0, Hilos::$env->reloads);
        }, install: false);
    }

    /**
     * The short active pause retains the same receiver without a PCNTL dispatch.
     *
     * @param int $signal Signal arriving in the active branch
     */
    #[DataProvider('processSignals')]
    public function testShortPassRetainsSignalReception(int $signal): void
    {
        $this->withManager(function (WorkerProcessSignalManager $manager) use ($signal): void {
            $manager->signalOnPause = $signal;
            $manager->finishActivePass();
            $manager->pollSignals();

            $this->assertSame([$signal === SIGHUP ? 'restart' : 'shutdown'], $manager->actions);
            $this->assertTrue($manager->exitRequested());
        });
    }

    /**
     * READ and POSIX readiness share one physical wait; neither bytes nor signal
     * marks are lost, and lifecycle runs after both native callbacks return.
     *
     * @param int $signal Signal ready together with the daemon frame
     */
    #[DataProvider('processSignals')]
    public function testSimultaneousReadAndSignalNeedOnlyANativeWaitAndDrain(int $signal): void
    {
        $client = new WorkerProcessSignalRecordingClient();
        $this->withManager(function (WorkerProcessSignalManager $manager) use ($client, $signal): void {
            $peer = $this->connectPair($client);
            $frame = new WorkerRegisterDTO(workerIndex: 1, monopolistic: false);
            try {
                $client->awaitReadable(0.0);
                $wire = $frame->toJson() . "\n";
                $this->assertSame(strlen($wire), socket_write($peer, $wire));
                $this->assertTrue(posix_kill(posix_getpid(), $signal));

                $manager->wait(600.0);

                $this->assertTrue($this->property($client, 'waitReadable'));
                $this->assertFalse($this->property($client, 'waitTimer')->pending(Event::TIMEOUT));
                $this->assertSame(0, $client->polls);
                $this->assertSame([$signal === SIGHUP ? 'restart' : 'shutdown'], $manager->actions);
                $this->assertFalse($manager->lifecycleInsideLoop);
                $this->assertSame($signal === SIGHUP ? 1 : 0, Hilos::$env->reloads);
                $this->assertTrue($manager->exitRequested());
                $this->assertSame([], $client->consumeProcessSignals());
                $this->assertNull($client->getNextMessage());
                $client->read();
                $this->assertSame($frame->toArray(), $client->getNextMessage()?->toArray());
                $this->assertNull($client->getNextMessage());
            } finally {
                socket_close($peer);
            }
        }, client: $client);
    }

    /** The receiver remains live during the last write without abandoning its buffered frames. */
    public function testSignalReceptionRemainsActiveDuringFinalSend(): void
    {
        $client = new WorkerProcessSignalFinalSendClient();
        $this->withManager(function (WorkerProcessSignalManager $manager) use ($client): void {
            $peer = $this->connectPair($client);
            $frame = new WorkerRegisterDTO(workerIndex: 1, monopolistic: false);
            $client->send($frame);
            try {
                $manager->runCleanup();

                $this->assertSame(1, $client->writableWaits);
                $this->assertSame(['shutdown'], $manager->actions);
                $this->assertFalse($manager->lifecycleInsideLoop);
                $this->assertTrue($manager->cleanedUp);
                $this->assertSame($frame->toJson() . "\n", socket_read($peer, 65536));
                $this->assertSame([], $this->property($client, 'processSignalEvents'));
            } finally {
                socket_close($peer);
            }
        }, client: $client);
    }

    /** An unread persistent socket must not make the nonblocking signal poll spin. */
    public function testSignalPollProcessesUnreadSocketOnlyOnce(): void
    {
        $this->withManager(function (WorkerProcessSignalManager $manager): void {
            $client = $manager->client();
            $peer = $this->connectPair($client);
            try {
                $client->awaitReadable(0.0);
                $event = $this->property($client, 'waitEvent');
                $this->assertTrue($event->del());
                $calls = 0;
                $this->assertTrue($event->set(
                    $this->property($client, 'waitBase'),
                    $client->getSocket(),
                    Event::READ | Event::PERSIST,
                    static function () use (&$calls): void {
                        $calls++;
                        if ($calls > 1) {
                            throw new RuntimeException('Signal polling spun on unread input.');
                        }
                    },
                ));
                $this->assertTrue($event->add());
                $this->assertSame(1, socket_write($peer, 'x'));

                $this->assertSame([], $client->consumeProcessSignals());
                $this->assertSame(0, $calls, 'Draining marks must not dispatch a ready fd.');
                $client->pollProcessSignals();
                $this->assertSame([], $client->consumeProcessSignals());
                $this->assertSame(1, $calls);
            } finally {
                socket_close($peer);
            }
        });
    }

    /** EOF removes fd watches while signals remain received until explicit cleanup. */
    public function testSignalReceptionSurvivesEofUntilCleanup(): void
    {
        $this->withManager(function (WorkerProcessSignalManager $manager): void {
            $client = $manager->client();
            $peer = $this->connectPair($client);
            $client->awaitReadable(0.0);
            socket_close($peer);
            $client->read();
            $this->assertTrue($client->isConnectionLost());
            $this->assertNull($this->property($client, 'waitEvent'));
            $this->assertNull($this->property($client, 'waitTimer'));

            $this->assertTrue(posix_kill(posix_getpid(), SIGTERM));
            $manager->runCleanup();

            $this->assertSame(['shutdown'], $manager->actions);
            $this->assertSame([], $this->property($client, 'processSignalEvents'));
            $this->assertNull($client->getSocket());
            $this->assertTrue($manager->cleanedUp);
        });
    }

    /** @return iterable<string, array{int}> Every worker lifecycle signal */
    public static function processSignals(): iterable
    {
        yield 'SIGTERM' => [SIGTERM];
        yield 'SIGINT' => [SIGINT];
        yield 'SIGHUP' => [SIGHUP];
    }

    /**
     * Isolates libevent's exclusive POSIX ownership inside the PHPUnit process.
     *
     * @param callable(WorkerProcessSignalManager): void $assertManager Assertions using real signal delivery
     * @param bool $install Install before assertions, or let run() install
     * @param ?WorkerDaemonClient $client Optional transport with a prepared final-write event
     */
    private function withManager(callable $assertManager, bool $install = true, ?WorkerDaemonClient $client = null): void
    {
        $signals = [SIGTERM, SIGINT, SIGHUP];
        $handlers = [];
        foreach ($signals as $signal) {
            $handlers[$signal] = pcntl_signal_get_handler($signal);
        }
        $this->assertTrue(pcntl_sigprocmask(SIG_BLOCK, $signals, $originalMask));
        $env = Hilos::$env;
        Hilos::$env = new WorkerProcessSignalEnv();
        $manager = new WorkerProcessSignalManager();
        try {
            if ($client !== null) {
                $manager->useClient($client);
            }
            if ($install) {
                $manager->installSignals();
                $manager->installSignals();
                $this->assertCount(3, $this->property($manager->client(), 'processSignalEvents'));
            }
            $this->assertTrue(pcntl_sigprocmask(SIG_UNBLOCK, $signals));
            $assertManager($manager);
        } finally {
            $manager->closeClient();
            foreach ($handlers as $signal => $handler) {
                pcntl_signal($signal, $handler);
            }
            $this->assertTrue(pcntl_sigprocmask(SIG_SETMASK, $originalMask));
            Hilos::$env = $env;
            Hilos::$sr = null;
            SourceInterestRegistry::readsWhatItMounts();
        }
    }

    /**
     * @param WorkerDaemonClient $client Existing receiver to give a daemon link
     * @return Socket Peer end for readiness and EOF
     */
    private function connectPair(WorkerDaemonClient $client): Socket
    {
        $pair = [];
        $this->assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        $this->assertTrue(socket_set_nonblock($pair[0]));
        new ReflectionProperty(WorkerDaemonClient::class, 'socket')->setValue($client, $pair[0]);
        new ReflectionProperty(WorkerDaemonClient::class, 'state')->setValue($client, DaemonConnectionState::CONNECTED);
        return $pair[1];
    }

    /**
     * @param WorkerDaemonClient $client Receiver under observation
     * @param string $name Private wait handle or signal record
     * @return mixed Local wait state
     */
    private function property(WorkerDaemonClient $client, string $name): mixed
    {
        return new ReflectionProperty(WorkerDaemonClient::class, $name)->getValue($client);
    }

    /**
     * @param WorkerManager $manager Lifecycle state under observation
     * @param string $name Private worker record
     * @return mixed Local worker state
     */
    private function workerProperty(WorkerManager $manager, string $name): mixed
    {
        return new ReflectionProperty(WorkerManager::class, $name)->getValue($manager);
    }
}

/** Runs real lifecycle handlers while exposing only test observations. */
final class WorkerProcessSignalManager extends WorkerManager
{
    /** @var list<string> Lifecycle actions outside event callbacks */
    public array $actions = [];
    public bool $lifecycleInsideLoop = false;
    public bool $connectAttempted = false;
    public bool $cleanedUp = false;
    public int $ticks = 0;
    public ?int $signalOnInstall = null;
    public int $signalOnPause = SIGTERM;

    public function __construct()
    {
        parent::__construct(1);
    }

    /** Installs the real receiver without running the worker. */
    public function installSignals(): void
    {
        $this->setupSignalHandlers();
    }

    /** @param WorkerDaemonClient $client Transport prepared before installing the receiver */
    public function useClient(WorkerDaemonClient $client): void
    {
        $this->daemonClient = $client;
    }

    /** @return WorkerDaemonClient The sole process signal receiver */
    public function client(): WorkerDaemonClient
    {
        return $this->daemonClient;
    }

    /** Releases process signal ownership before the test restores PHP handlers. */
    public function closeClient(): void
    {
        $this->daemonClient?->close();
    }

    /** @param float $seconds Remaining wait budget */
    public function wait(float $seconds): void
    {
        $this->awaitDaemonWork($this->waitTime() + $seconds);
    }

    /** Applies pending signals through the same door as run(). */
    public function pollSignals(): void
    {
        Closure::bind(function (): void {
            $this->dispatchProcessSignals();
        }, $this, WorkerManager::class)();
    }

    /** Exercises the active pause and its still-installed receiver. */
    public function finishActivePass(): void
    {
        Closure::bind(function (): void {
            $this->waitForWork(microtime(true), true);
        }, $this, WorkerManager::class)();
    }

    /** Runs actual stop cleanup. */
    public function runCleanup(): void
    {
        $this->cleanup();
    }

    /** @return bool Whether ordinary lifecycle code requested exit */
    public function exitRequested(): bool
    {
        return $this->shouldExit;
    }

    /** Uses the real installation and can post a ready signal before connect(). */
    protected function setupSignalHandlers(): void
    {
        parent::setupSignalHandlers();
        if ($this->signalOnInstall !== null) {
            TestCase::assertTrue(posix_kill(posix_getpid(), $this->signalOnInstall));
        }
    }

    /** Avoids replacing PHPUnit's process error and shutdown hooks. */
    protected function setupErrorHandling(): void
    {
    }

    /** This seam must never be reached when the early stop is already delivered. */
    protected function connectToDaemon(): void
    {
        $this->connectAttempted = true;
        throw new RuntimeException('A signal before connect must stop the worker first.');
    }

    /** Counts accidental project work. */
    protected function onTick(): void
    {
        $this->ticks++;
    }

    /** Marks ordinary stop semantics and records its call stack. */
    protected function onShutdownSignal(): void
    {
        parent::onShutdownSignal();
        $this->recordAction('shutdown');
    }

    /** Records the restart after BaseManager has reloaded the environment. */
    protected function onRestartSignal(): void
    {
        $this->recordAction('restart');
    }

    /**
     * Posts a signal in the short branch without waiting out its cadence.
     *
     * @param float $loopStartTime Unused pass start
     * @param int $targetLoopTimeMicroseconds Unused pause duration
     */
    protected function sleepWithPreciseTiming(float $loopStartTime, int $targetLoopTimeMicroseconds = 10000): void
    {
        TestCase::assertTrue(posix_kill(posix_getpid(), $this->signalOnPause));
    }

    /** Retains the real cleanup and observes transport release. */
    protected function cleanup(): void
    {
        parent::cleanup();
        $this->cleanedUp = $this->daemonClient === null;
    }

    /** @return SignalRouter Unused project routing */
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    /** @return AgentManager Empty holder for early lifecycle paths */
    protected function createAgentManager(): AgentManager
    {
        return new class extends AgentManager {
            /**
             * @param string $agentType Unused type
             * @param ?string $agentIndex Unused index
             * @return AgentInterface Never returns
             */
            protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
            {
                throw new RuntimeException('A process signal must not start an agent.');
            }
        };
    }

    /** @param string $action Lifecycle action already applied */
    private function recordAction(string $action): void
    {
        $this->actions[] = $action;
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (($frame['class'] ?? null) === EventBase::class && $frame['function'] === 'loop') {
                $this->lifecycleInsideLoop = true;
            }
        }
    }
}

/** Observes the real BaseManager restart's environment reload without reading files. */
final class WorkerProcessSignalEnv extends EnvAccessor
{
    public int $reloads = 0;

    /** Counts reloads requested by ordinary lifecycle code. */
    public function reload(): void
    {
        $this->reloads++;
    }
}

/** Posts a real POSIX signal at the final-write boundary, with the same receiver installed. */
final class WorkerProcessSignalFinalSendClient extends WorkerDaemonClient
{
    public int $writableWaits = 0;

    /**
     * @param float $timeoutSeconds Existing final-send safety interval
     */
    public function awaitWritable(float $timeoutSeconds): void
    {
        $this->writableWaits++;
        TestCase::assertTrue(posix_kill(posix_getpid(), SIGTERM));
        parent::awaitWritable($timeoutSeconds);
    }
}

/** Counts real nonblocking event pumps separately from native socket waiting. */
final class WorkerProcessSignalRecordingClient extends WorkerDaemonClient
{
    public int $polls = 0;

    /** Dispatches native ready events and retains their marks for the ordinary drain. */
    public function pollProcessSignals(): void
    {
        $this->polls++;
        parent::pollProcessSignals();
    }
}
