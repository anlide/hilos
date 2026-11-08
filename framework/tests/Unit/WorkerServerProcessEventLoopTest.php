<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\WorkerConstants;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\EventLoop\EventLoopTimer;
use Hilos\Core\Exception\Process\FailedToReadStdOutException;
use Hilos\Core\Process;
use Hilos\Core\Router\SignalRouter;
use Hilos\Hilos;
use Hilos\Socket\Server\WorkerServer;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/** Same-process scheduling outcomes; real worker loss and final deliveries use the cluster stand. */
final class WorkerServerProcessEventLoopTest extends TestCase
{
    private EventLoop $loop;

    private ProcessLoopWorkerServer $server;

    private ?SignalRouter $previousRouter;

    public function testRegistrationSuccessLeavesProcessSweepAliveAndTrafficDoesNotMoveIt(): void
    {
        $process = $this->seed(1);
        $this->server->attachEventLoop($this->loop);
        $this->pump();
        self::assertSame(1, $this->server->bootstraps);
        $before = $process->ticks;
        $deadline = $this->timer()->getDeadline();
        self::assertNotNull($deadline);

        for ($turn = 0; $turn < 4; ++$turn) {
            $this->loop->createReadyWork(static function (): void {})->markReady();
            $this->loop->tick();
        }
        self::assertSame($before, $process->ticks);
        self::assertSame($deadline, $this->timer()->getDeadline());

        $this->timer()->armAt(hrtime(true) / 1e9 - 1.0);
        $this->pump();
        self::assertGreaterThan($before, $process->ticks);
        self::assertSame(1, $this->server->bootstraps);
        self::assertFalse($this->loop->hasReadyWork());
    }

    public function testCoalescedSigchldSettlesEveryExitedChildWithoutWaitingForSweep(): void
    {
        $first = $this->seed(1);
        $second = $this->seed(2);
        $this->server->attachEventLoop($this->loop);
        $this->pump();
        $deadline = $this->timer()->getDeadline();
        $first->running = false;
        $second->running = false;
        self::assertTrue(posix_kill(getmypid(), SIGCHLD));
        self::assertTrue(posix_kill(getmypid(), SIGCHLD));
        $this->pump();

        self::assertSame([], $this->server->liveWorkerPictures());
        self::assertSame(4, $this->server->rosterRevision());
        self::assertNotNull($deadline);
        self::assertNull($this->timer()->getDeadline(), 'an empty zero-minimum pool owes no periodic sweep');
        self::assertFalse($this->loop->hasReadyWork());
    }

    public function testShutdownKeepsChildObservationAndWaitsWithoutProcessPolling(): void
    {
        $process = $this->seed(1);
        $this->server->attachEventLoop($this->loop);
        $this->pump();
        $this->server->prepareShutdown();
        $this->pump();
        self::assertSame(1, $process->stops);
        self::assertNull(new ReflectionProperty(WorkerServer::class, 'processSweepTimer')->getValue($this->server));
        self::assertFalse($this->server->isReadyToShutdown());
        $before = $process->ticks;
        for ($pass = 0; $pass < 8; ++$pass) {
            $this->server->onTick();
        }
        $this->pump();
        self::assertSame($before, $process->ticks);
        self::assertFalse($this->loop->hasReadyWork());

        $process->running = false;
        self::assertTrue(posix_kill(getmypid(), SIGCHLD));
        $this->pump();
        self::assertTrue($this->server->isReadyToShutdown());
    }

    public function testDetachRebindAndReusedSlotRejectOldIncarnation(): void
    {
        $old = $this->seed(1);
        $this->server->attachEventLoop($this->loop);
        $this->pump();
        $oldRow = $this->workers()['regular:1'];
        $this->timer()->armAt(hrtime(true) / 1e9 - 1.0);
        self::assertTrue(posix_kill(getmypid(), SIGCHLD));
        $this->server->detachEventLoop();
        $before = $old->ticks;
        $replacement = $this->seed(1);
        $this->pump();
        self::assertSame($before, $old->ticks);
        self::assertSame(0, $replacement->ticks);

        $this->server->attachEventLoop($this->loop);
        $this->pump();
        $replacementBefore = $replacement->ticks;
        new ReflectionMethod(WorkerServer::class, 'runWorkerProcess')->invoke($this->server, 'regular:1', $oldRow);
        self::assertSame($before, $old->ticks);
        self::assertSame($replacementBefore, $replacement->ticks);
        self::assertCount(1, $this->server->liveWorkerPictures());
    }

    public function testFailedChildIsRetiredAndReapedWithoutTouchingReplacement(): void
    {
        $process = $this->seed(1);
        $process->failRead = true;
        $this->server->attachEventLoop($this->loop);
        $this->pump();
        self::assertSame(1, $process->kills);
        self::assertSame([], $this->server->liveWorkerPictures());
        self::assertSame([], new ReflectionProperty(WorkerServer::class, 'retiredWorkerProcesses')->getValue($this->server));
        self::assertFalse($this->loop->hasReadyWork());
    }

    protected function setUp(): void
    {
        $this->previousRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        $this->loop = new EventLoop();
        $this->server = new ProcessLoopWorkerServer();
        new ReflectionProperty(WorkerServer::class, 'agentManager')->setValue($this->server, $this->createMock(AgentManagerDaemon::class));
        new ReflectionProperty(WorkerServer::class, 'cachedLogDirectory')->setValue($this->server, '/tmp');
        new ReflectionProperty(WorkerServer::class, 'minRegular')->setValue($this->server, 0);
        new ReflectionProperty(WorkerServer::class, 'maxRegular')->setValue($this->server, 0);
        new ReflectionProperty(WorkerServer::class, 'minMonopolistic')->setValue($this->server, 0);
    }

    protected function tearDown(): void
    {
        $this->server->detachEventLoop();
        $this->loop->close();
        Hilos::$sr = $this->previousRouter;
    }

    /**
     * @param int $index Reusable worker slot
     * @return ProcessLoopChild Captured process
     */
    private function seed(int $index): ProcessLoopChild
    {
        $process = new ProcessLoopChild();
        $rows = $this->workers();
        $rows['regular:' . $index] = [
            WorkerConstants::FIELD_WORKER_PROCESS => $process,
            WorkerConstants::FIELD_WORKER_INDEX => $index,
            WorkerConstants::FIELD_WORKER_TYPE => WorkerConstants::TYPE_REGULAR,
        ];
        new ReflectionProperty(WorkerServer::class, 'workers')->setValue($this->server, $rows);
        new ReflectionMethod(WorkerServer::class, 'recordWorkerStarted')->invoke($this->server, $index);
        return $process;
    }

    /** @return array<string, array<string, Process|string|int>> Current child incarnations */
    private function workers(): array
    {
        return new ReflectionProperty(WorkerServer::class, 'workers')->getValue($this->server);
    }

    /** @return EventLoopTimer Real native sweep timer */
    private function timer(): EventLoopTimer
    {
        return new ReflectionProperty(WorkerServer::class, 'processSweepTimer')->getValue($this->server);
    }

    private function pump(): void
    {
        for ($turn = 0; $turn < 8; ++$turn) {
            $this->loop->tick();
        }
    }
}

/** No child launch; bootstrap and the actual scheduling/cleanup paths remain observable. */
final class ProcessLoopWorkerServer extends WorkerServer
{
    public int $bootstraps = 0;

    public function __construct()
    {
    }

    protected function onStart(): void
    {
    }

    protected function onInitialWorkersReady(): void
    {
        ++$this->bootstraps;
    }

    /** Protected-mode diagnostics are outside this process scheduling fixture. */
    protected function publishProtectedModeAgentDiagnostics(): void
    {
    }
}

/** Process facts without a second process; the inherited binding uses the real native base. */
final class ProcessLoopChild extends Process
{
    public int $ticks = 0;
    public int $stops = 0;
    public int $kills = 0;
    public bool $running = true;
    public bool $failRead = false;

    public function __construct()
    {
    }

    public function tick(): void
    {
        ++$this->ticks;
        if ($this->failRead) {
            throw new FailedToReadStdOutException('test child read failure');
        }
    }

    /** @return array<string, mixed> Explicit child status */
    public function getStatus(): array
    {
        return [self::STATUS_RUNNING => $this->running];
    }

    public function isInFlight(): bool
    {
        return $this->running;
    }

    /** @param ?float $shutdownTimeout Existing stop budget */
    public function stop(?float $shutdownTimeout = null): void
    {
        ++$this->stops;
    }

    public function halt(): void
    {
        ++$this->kills;
        $this->running = false;
        $this->detachEventLoop();
    }

    public function __destruct()
    {
        $this->detachEventLoop();
    }
}
