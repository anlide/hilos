<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core;

use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\EventLoop\EventLoopTimer;
use Hilos\Core\Process;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** Native pipe/deadline bookkeeping is private to Process; no extra child is needed here. */
final class ProcessEventLoopTest extends TestCase
{
    private EventLoop $loop;

    private BoundIoProcess $process;

    /** @var list<resource> Same-process stream endpoints, standing in only for child pipes */
    private array $streams = [];

    public function testReadableOutputIsBoundedAndEofRemovesTheWatch(): void
    {
        [$pipe, $peer] = $this->pair();
        new ReflectionProperty(Process::class, 'pipes')->setValue($this->process, [1 => $pipe]);
        $payload = str_repeat('x', 131073);
        self::assertSame(strlen($payload), fwrite($peer, $payload));
        $output = '';
        $this->process->attachEventLoop($this->loop, function () use (&$output): void {
            $this->process->tick();
            $output .= $this->process->getStdOut();
        });
        $this->loop->tick();
        self::assertSame(65536, strlen($output), 'one owner portion must yield with readable output remaining');
        $this->pump();
        self::assertSame($payload, $output);

        fclose(array_pop($this->streams));
        $this->pump();
        self::assertSame(0, $this->loop->registeredCount());
        self::assertFalse($this->loop->hasReadyWork());
        self::assertSame($payload, $output);
    }

    public function testStdinBackpressureUsesWriteReadinessUntilEveryByteIsSent(): void
    {
        [$pipe, $peer] = $this->pair();
        new ReflectionProperty(Process::class, 'pipes')->setValue($this->process, [0 => $pipe]);
        $this->process->attachEventLoop($this->loop, $this->process->tick(...));
        $this->pump();
        $input = str_repeat('stdin', 200000);
        $this->process->sendInput($input);
        self::assertSame(1, $this->loop->registeredCount());
        $received = '';
        for ($portion = 0; $portion < 32; ++$portion) {
            $received .= stream_get_contents($peer);
            $this->loop->tick();
        }
        $received .= stream_get_contents($peer);
        self::assertSame($input, $received);
        self::assertSame(0, $this->loop->registeredCount());
        self::assertFalse($this->loop->hasReadyWork());
    }

    public function testStoredStopDeadlineFiresOnceAndUnrelatedWorkDoesNotExtendIt(): void
    {
        $deadline = hrtime(true) / 1e9 + 3600.0;
        new ReflectionProperty(Process::class, 'haltTime')->setValue($this->process, $deadline);
        $this->process->attachEventLoop($this->loop, $this->process->tick(...));
        $this->pump();
        self::assertSame(0, $this->process->halts);
        $timer = new ReflectionProperty(Process::class, 'stopTimer')->getValue($this->process);
        self::assertInstanceOf(EventLoopTimer::class, $timer);
        self::assertSame($deadline, $timer->getDeadline());
        $this->pump();
        self::assertSame($deadline, $timer->getDeadline());

        $due = hrtime(true) / 1e9 - 1.0;
        new ReflectionProperty(Process::class, 'haltTime')->setValue($this->process, $due);
        $timer->armAt($due);
        $this->pump();
        self::assertSame(1, $this->process->halts);
        self::assertNull($timer->getDeadline());
        self::assertFalse($this->loop->hasReadyWork());
    }

    public function testDetachCancelsDueOutputAndDeadlineBeforeRebind(): void
    {
        [$pipe, $peer] = $this->pair();
        new ReflectionProperty(Process::class, 'pipes')->setValue($this->process, [2 => $pipe]);
        new ReflectionProperty(Process::class, 'haltTime')->setValue($this->process, hrtime(true) / 1e9 - 1.0);
        $oldCalls = 0;
        $this->process->attachEventLoop($this->loop, function () use (&$oldCalls): void {
            ++$oldCalls;
        });
        self::assertSame(4, fwrite($peer, 'last'));
        $this->process->detachEventLoop();
        new ReflectionProperty(Process::class, 'haltTime')->setValue($this->process, null);
        $this->process->attachEventLoop($this->loop, $this->process->tick(...));
        $this->pump();
        self::assertSame(0, $oldCalls);
        self::assertSame(0, $this->process->halts);
        self::assertSame('last', $this->process->getStdErr());
        self::assertFalse($this->loop->hasReadyWork());
    }

    public function testKilledChildWithClosedOutputWaitsForExitWithoutAnEofLoop(): void
    {
        [$pipe] = $this->pair();
        fclose(array_pop($this->streams));
        $process = new AwaitingExitProcess();
        new ReflectionProperty(Process::class, 'pipes')->setValue($process, [1 => $pipe]);
        new ReflectionProperty(Process::class, 'killSent')->setValue($process, true);
        try {
            $process->attachEventLoop($this->loop, $process->halt(...));
            $this->pump();
            self::assertTrue($process->isInFlight(), 'a sent SIGKILL is not an exit observation');
            self::assertSame(0, $this->loop->registeredCount(), 'EOF must detach even while the killed child has not exited');
            self::assertFalse($this->loop->hasReadyWork());
            $checks = $process->statusChecks;
            $this->pump();
            self::assertSame($checks, $process->statusChecks);
        } finally {
            $process->detachEventLoop();
        }
    }

    protected function setUp(): void
    {
        $this->loop = new EventLoop();
        $this->process = new BoundIoProcess();
    }

    protected function tearDown(): void
    {
        $this->process->detachEventLoop();
        $this->loop->close();
        foreach ($this->streams as $stream) {
            fclose($stream);
        }
    }

    /** @return array{resource, resource} Nonblocking stream pair */
    private function pair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        foreach ($pair as $stream) {
            stream_set_blocking($stream, false);
            $this->streams[] = $stream;
        }
        return $pair;
    }

    private function pump(): void
    {
        for ($portion = 0; $portion < 8; ++$portion) {
            $this->loop->tick();
        }
    }
}

/** Uses real Process I/O, with only the absent OS child and its kill replaced. */
final class BoundIoProcess extends Process
{
    public int $halts = 0;

    public function __construct()
    {
    }

    /** @return array<string, mixed> Child stays alive while pipe readiness is exercised */
    public function getStatus(): array
    {
        return [self::STATUS_RUNNING => true];
    }

    public function halt(): void
    {
        ++$this->halts;
        new ReflectionProperty(Process::class, 'haltTime')->setValue($this, null);
    }

    public function __destruct()
    {
        $this->detachEventLoop();
    }
}

/** The kill was sent, but the specific child's exit has not yet been observed. */
final class AwaitingExitProcess extends Process
{
    public int $statusChecks = 0;

    public function __construct()
    {
    }

    /** @return array<string, mixed> The OS still reports the owned child running */
    public function getStatus(): array
    {
        ++$this->statusChecks;
        return [self::STATUS_RUNNING => true];
    }
}
