<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\EventLoop;

use Event;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\EventLoop\Exception\EventLoopException;
use Hilos\HilosException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Socket;
use RuntimeException;
use WeakReference;

/** Native watches defer owner work, survive independent replacement, and detach before close. */
final class EventLoopIoTest extends TestCase
{
    private EventLoop $loop;

    /** @var list<Socket> Pair owned by the scenario */
    private array $sockets = [];

    /** Pipe resource ids and Socket object ids inhabit independent numeric domains. */
    public function testStreamAndSocketRegistrationsHaveSeparateIdentityDomains(): void
    {
        $streams = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($streams);
        foreach ($streams as $stream) {
            stream_set_blocking($stream, false);
        }
        $seen = [];
        try {
            $key = new ReflectionMethod(EventLoop::class, 'socketKey');
            $this->assertSame('resource:' . (int)$streams[0], $key->invoke($this->loop, $streams[0]));
            $this->assertSame('object:' . spl_object_id($this->sockets[0]), $key->invoke($this->loop, $this->sockets[0]));
            $this->assertNotSame($key->invoke($this->loop, $streams[0]), $key->invoke($this->loop, (int)$streams[0]));
            $this->loop->registerRead($streams[0], function ($stream) use (&$seen): void {
                $seen[] = stream_get_contents($stream);
            });
            $this->loop->registerRead($this->sockets[0], function ($socket) use (&$seen): void {
                $seen[] = socket_read($socket, 1);
            });
            fwrite($streams[1], 'pipe');
            socket_write($this->sockets[1], 's');
            $this->loop->tick();
            $this->assertCount(2, $seen);
            $this->assertContains('pipe', $seen);
            $this->assertContains('s', $seen);
            $this->loop->unregister($streams[0]);
            $this->assertSame(1, $this->loop->registeredCount());
            socket_write($this->sockets[1], 't');
            $this->loop->tick();
            $this->assertSame('t', $seen[2]);
        } finally {
            $this->loop->unregister($streams[0]);
            foreach ($streams as $stream) {
                fclose($stream);
            }
        }
    }

    /** READ and WRITE replace only their own side, and a readable level yields after one portion. */
    public function testIndependentReadWriteReplacementAndBoundedReadableLevel(): void
    {
        $seen = [];
        $this->loop->registerRead($this->sockets[0], function () use (&$seen): void {
            $seen[] = 'old-read';
        });
        $this->loop->registerWrite($this->sockets[0], function () use (&$seen): void {
            $seen[] = 'old-write';
        });
        $this->loop->registerRead($this->sockets[0], function ($socket, int $flags) use (&$seen): void {
            $seen[] = ['read', $flags, socket_read($socket, 1)];
        });
        $this->loop->registerWrite($this->sockets[0], function ($socket, int $flags) use (&$seen): void {
            $seen[] = ['write', $flags];
            $this->loop->unregisterWrite($socket);
        });
        $this->assertSame(1, $this->loop->registeredCount());
        socket_write($this->sockets[1], 'ab');
        $this->loop->runOnce();
        $this->assertCount(2, $seen, 'a level that remains readable must yield after its first portion');
        $this->assertContains(['read', Event::READ, 'a'], $seen);
        $this->assertContains(['write', Event::WRITE], $seen);
        $this->assertSame(1, $this->loop->registeredCount());
        $this->loop->tick();
        $this->assertSame(['read', Event::READ, 'b'], $seen[2]);
        $this->loop->unregisterRead($this->sockets[0]);
        $this->assertSame(0, $this->loop->registeredCount());
        $this->loop->tick();
        $this->assertCount(3, $seen);
    }

    /** Detach cancels native-activated deferred reads and writes before the socket is closed. */
    public function testDetachSuppressesPendingCallbacksBeforeSocketClose(): void
    {
        $calls = 0;
        $this->loop->registerRead($this->sockets[0], function () use (&$calls): void {
            ++$calls;
        });
        $this->loop->registerWrite($this->sockets[0], function () use (&$calls): void {
            ++$calls;
        });
        socket_write($this->sockets[1], 'x');
        $this->loop->createReadyWork(function (): void {
            $socket = array_shift($this->sockets);
            $this->loop->unregister($socket);
            $this->loop->unregister($socket);
            socket_close($socket);
        })->markReady();
        $this->loop->runOnce();
        $this->loop->tick();
        $this->assertSame(0, $calls);
        $this->assertSame(0, $this->loop->registeredCount());
        $this->assertFalse($this->loop->hasReadyWork());
    }

    /** An old callback can replace itself without rearming the old watch after returning. */
    public function testReplacementInsideAReadRetainsOnlyTheNewHandler(): void
    {
        $seen = [];
        $this->loop->registerRead($this->sockets[0], function () use (&$seen): void {
            $seen[] = socket_read($this->sockets[0], 1);
            $this->loop->registerRead($this->sockets[0], function () use (&$seen): void {
                $seen[] = socket_read($this->sockets[0], 1);
            });
            throw new HilosException('the originating reader has already logged its failure');
        });
        socket_write($this->sockets[1], 'ab');
        $this->loop->tick();
        $this->assertSame(['a'], $seen, 'the legacy read wrapper still contains failures');
        $this->loop->tick();
        $this->assertSame(['a', 'b'], $seen);
        $this->assertSame(1, $this->loop->registeredCount());
    }

    /** Native activation itself yields before all ready timer owners have been notified. */
    public function testNativeCallbackBudgetRetainsItsRemainder(): void
    {
        $timers = [];
        $seen = [];
        for ($owner = 0; $owner < 257; ++$owner) {
            $timer = $this->loop->createTimer(function () use ($owner, &$seen): void {
                $seen[] = $owner;
            });
            $timer->armAt(hrtime(true) / 1e9 - 1.0);
            $timers[] = $timer;
        }
        $this->loop->runOnce();
        $activated = 0;
        foreach ($timers as $timer) {
            if ($timer->getDeadline() === null) {
                ++$activated;
            }
        }
        $this->assertGreaterThan(0, $activated);
        $this->assertLessThan(257, $activated, 'native callbacks must yield before the whole active set');
        $this->assertTrue($this->loop->hasReadyWork());
        for ($pass = 0; $pass < 257 && $this->loop->hasReadyWork(); ++$pass) {
            $this->loop->runOnce();
        }
        sort($seen);
        $this->assertSame(range(0, 256), $seen);
        $this->assertFalse($this->loop->hasReadyWork());
    }

    /** A ready POSIX signal defers its handler; unregister restores the previous process handler. */
    public function testSignalDeliveryAndReleaseStayInTheSameProcess(): void
    {
        $previous = pcntl_signal_get_handler(SIGUSR2);
        $restoredCalls = 0;
        pcntl_signal(SIGUSR2, function () use (&$restoredCalls): void {
            ++$restoredCalls;
        });
        try {
            $seen = [];
            $this->loop->registerSignal(SIGUSR2, function (int $signal) use (&$seen): void {
                $seen[] = $signal;
            });
            $this->loop->createReadyWork(function () use (&$seen): void {
                $seen[] = 'ready-first';
            })->markReady();
            $this->assertTrue(posix_kill(getmypid(), SIGUSR2));
            $this->loop->runOnce();
            $this->assertSame(['ready-first', SIGUSR2], $seen);
            $this->assertSame(0, $restoredCalls);

            $this->loop->createReadyWork(function (): void {
                $this->loop->unregisterSignal(SIGUSR2);
            })->markReady();
            $this->assertTrue(posix_kill(getmypid(), SIGUSR2));
            $this->loop->runOnce();
            $this->assertSame(['ready-first', SIGUSR2], $seen, 'detach cancels the pending native delivery');
            $this->loop->unregisterSignal(SIGUSR2);
            $this->assertTrue(posix_kill(getmypid(), SIGUSR2));
            pcntl_signal_dispatch();
            $this->assertSame(1, $restoredCalls);
        } finally {
            $this->loop->unregisterSignal(SIGUSR2);
            pcntl_signal(SIGUSR2, $previous);
        }
    }

    public function testFailedSignalInstallationReleasesItsPartialOwner(): void
    {
        set_error_handler(static function (int $severity, string $message): never {
            throw new RuntimeException($message);
        });
        try {
            try {
                $this->loop->registerSignal(SIGKILL, static function (): void {});
                $this->fail('SIGKILL cannot have a native signal receiver');
            } catch (EventLoopException) {
                $this->assertFalse($this->loop->hasReadyWork());
            }
        } finally {
            restore_error_handler();
        }
        $calls = 0;
        $this->loop->registerSignal(SIGUSR2, static function () use (&$calls): void { ++$calls; });
        $this->assertTrue(posix_kill(getmypid(), SIGUSR2));
        $this->loop->runOnce();
        $this->assertSame(1, $calls);
        $this->assertFalse($this->loop->hasReadyWork());
    }

    public function testClosedSocketInstallationRefusesWithoutKeepingAWatch(): void
    {
        $closed = array_shift($this->sockets);
        socket_close($closed);
        set_error_handler(static function (int $severity, string $message): never {
            throw new RuntimeException($message);
        });
        try {
            try {
                $this->loop->registerRead($closed, static function (): void {});
                $this->fail('a closed descriptor cannot be watched');
            } catch (EventLoopException) {
                $this->assertSame(0, $this->loop->registeredCount());
                $this->assertFalse($this->loop->hasReadyWork());
            }
        } finally {
            restore_error_handler();
        }
        $written = 0;
        $this->loop->registerWrite($this->sockets[0], function ($socket) use (&$written): void {
            ++$written;
            $this->loop->unregisterWrite($socket);
        });
        $this->loop->runOnce();
        $this->assertSame(1, $written);
        $this->assertSame(0, $this->loop->registeredCount());
    }

    public function testCleanupReleasesTheIoOwnerGraphWithoutACollectorPass(): void
    {
        $loop = new EventLoop();
        $loop->registerRead($this->sockets[0], static function (): void {});
        $loop->registerWrite($this->sockets[0], static function (): void {});
        $loop->cleanup();
        $owner = WeakReference::create($loop);
        unset($loop);
        $this->assertNull($owner->get());
    }

    /** Opens nonblocking sockets and the real native loop. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->loop = new EventLoop();
        $this->assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $this->sockets));
        foreach ($this->sockets as $socket) {
            socket_set_nonblock($socket);
        }
    }

    /** Releases native references before closing descriptors. */
    protected function tearDown(): void
    {
        $this->loop->cleanup();
        foreach ($this->sockets as $socket) {
            socket_close($socket);
        }
        parent::tearDown();
    }
}
