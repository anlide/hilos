<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Socket;

use Hilos\Core\EventLoop\EventLoop;
use Hilos\Socket\Transport\PlainSocketTransport;
use PHPUnit\Framework\TestCase;
use Socket;

/** The real socket transport distinguishes would-block, EOF and an unchanged socket failure. */
final class PlainSocketTransportTest extends TestCase
{
    private PlainSocketTransport $transport;

    private Socket $socket;

    private Socket $peer;

    /** No input is not EOF; after peer shutdown, the same empty value carries EOF explicitly. */
    public function testWouldBlockReadIsEmptyWithoutAnErrorOrEof(): void
    {
        $this->assertSame('', $this->transport->read(32));
        $this->assertFalse($this->transport->isEndOfStream());
        $this->assertSame(0, socket_last_error($this->socket));
        $this->assertSame(5, socket_write($this->peer, 'input'));
        $this->assertTrue(socket_shutdown($this->peer, 1));
        $this->assertSame('input', $this->transport->read(32));
        $this->assertFalse($this->transport->isEndOfStream());
        $this->assertSame('', $this->transport->read(32));
        $this->assertTrue($this->transport->isEndOfStream());
    }

    /** A real EAGAIN normalizes to zero; a later attached WRITE wake resumes after peer drain. */
    public function testWouldBlockWriteWaitsForCapacityAndEmptyWriteDisarms(): void
    {
        $chunk = str_repeat('x', 65_536);
        $written = 0;
        for ($attempt = 0; $attempt < 64; ++$attempt) {
            $count = $this->transport->write($chunk);
            $this->assertIsInt($count);
            if ($count === 0) {
                break;
            }
            $written += $count;
        }
        $this->assertSame(0, $count);
        $this->assertGreaterThan(0, $written);
        $this->assertSame(0, socket_last_error($this->socket));

        $loop = new EventLoop();
        $calls = 0;
        $work = $loop->createReadyWork(static function () use (&$calls): void {
            ++$calls;
        });
        try {
            $this->transport->attachEventLoop($loop, $work->markReady(...));
            $this->assertSame(0, $this->transport->write('later'));
            $this->assertSame(0, socket_last_error($this->socket));
            $loop->tick();
            $this->assertFalse($loop->hasReadyWork());
            $this->assertSame(0, $calls);

            $this->assertSame(str_repeat('x', $written), socket_read($this->peer, $written));
            $loop->tick();
            $loop->tick();
            $this->assertSame(1, $calls);
            $this->assertSame(5, $this->transport->write('later'));
            $this->assertSame('later', socket_read($this->peer, 5));

            $this->assertGreaterThan(0, $this->transport->write($chunk));
            $this->assertSame(0, $this->transport->write(''));
            $this->assertIsString(socket_read($this->peer, 65_536));
            $loop->tick();
            $loop->tick();
            $this->assertSame(1, $calls, 'no WRITE wake survives an explicitly emptied buffer');
        } finally {
            $this->transport->detachEventLoop();
            $work->close();
            $loop->close();
        }
    }

    /** Closing after native activation cancels the old binding before descriptor release. */
    public function testCloseDetachesBeforeSocketReleaseAndIsRepeatable(): void
    {
        $loop = new EventLoop();
        $calls = 0;
        try {
            $this->transport->attachEventLoop($loop, static function () use (&$calls): void {
                ++$calls;
            });
            $this->assertSame(1, socket_write($this->peer, 'x'));
            $loop->createReadyWork($this->transport->close(...))->markReady();
            $loop->tick();
            $this->transport->close();
            $this->transport->attachEventLoop($loop, static function () use (&$calls): void {
                ++$calls;
            });
            $loop->tick();
            $this->assertSame(0, $calls);
            $this->assertSame(0, $loop->registeredCount());
            $this->assertFalse($loop->hasReadyWork());
        } finally {
            $this->transport->detachEventLoop();
            $loop->close();
        }
    }

    /** Opens a pair with finite, already observable send capacity. */
    protected function setUp(): void
    {
        $pair = [];
        $this->assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        [$this->socket, $this->peer] = $pair;
        foreach ($pair as $socket) {
            $this->assertTrue(socket_set_nonblock($socket));
        }
        $this->assertTrue(socket_set_option($this->socket, SOL_SOCKET, SO_SNDBUF, 4096));
        $this->transport = new PlainSocketTransport($this->socket);
    }

    /** The transport closes its own descriptor, including in tests that already closed it. */
    protected function tearDown(): void
    {
        $this->transport->close();
        socket_close($this->peer);
    }
}
