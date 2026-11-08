<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Socket;

use Hilos\Core\EventLoop\EventLoop;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Socket\Client\AbstractClient;
use PHPUnit\Framework\TestCase;
use Socket;

/** Real native readiness drives one client portion without a clock, a daemon or another process. */
final class ClientEventLoopTest extends TestCase
{
    private EventLoop $loop;

    private ClientEventLoopTestClient $client;

    private Socket $peer;

    private ?EnvAccessor $previousEnv;

    private string|false $previousReadSize;

    /** Initial binding and several new appends coalesce; idle sockets never wake on WRITE. */
    public function testOutputNotificationsCoalesceAndIdleHasNoWriteWatch(): void
    {
        $calls = 0;
        $this->bind($calls);
        $this->client->send('one');
        $this->client->send('two');
        $this->loop->tick();

        $this->assertSame(1, $calls);
        $this->assertSame('onetwo', $this->drainPeer());
        $this->assertFalse($this->client->hasPendingWrite());
        $this->assertFalse($this->loop->hasReadyWork());
        for ($pass = 0; $pass < 4; ++$pass) {
            $this->loop->tick();
        }
        $this->assertSame(1, $calls);
        $this->assertSame(1, $this->loop->registeredCount(), 'READ remains armed on an idle client');

        $this->client->markShouldClose();
        $this->client->markShouldClose();
        $this->loop->tick();
        $this->assertSame(2, $calls);
        $this->assertSame(0, $this->loop->registeredCount());
        $this->assertFalse($this->loop->hasReadyWork());
    }

    /** A readable remainder gets another bounded portion without any later peer write. */
    public function testIncomingRemainderContinuesInBoundedPortions(): void
    {
        $calls = 0;
        $this->bind($calls);
        $this->loop->tick();
        $this->assertSame(24, socket_write($this->peer, 'abcdefghijklmnopqrstuvwx'));
        for ($pass = 0; $pass < 8; ++$pass) {
            $before = $calls;
            $this->loop->tick();
            $this->assertLessThanOrEqual(1, $calls - $before);
        }

        $this->assertSame(['abcdefgh', 'ijklmnop', 'qrstuvwx'], $this->client->portions);
        $this->assertSame(4, $calls);
        $this->assertFalse($this->client->shouldClose());
        $this->assertFalse($this->loop->hasReadyWork());
    }

    /** A stalled writer still reads; only peer drain permits the remaining bytes to move. */
    public function testBackpressureWaitsForWriteWhileReadRemainsIndependent(): void
    {
        $calls = 0;
        $this->bind($calls);
        $payload = str_repeat('outbound', 16_384);
        $this->client->send($payload);
        $this->loop->tick();
        $this->assertTrue($this->client->hasPendingWrite());
        $this->assertFalse($this->loop->hasReadyWork());
        for ($pass = 0; $pass < 4; ++$pass) {
            $this->loop->tick();
        }
        $this->assertSame(1, $calls, 'a full send buffer must not spin ready work');

        $this->client->send('tail');
        $this->loop->tick();
        $this->assertSame(2, $calls, 'new output gets one attempt, even while transport-blocked');
        $this->assertFalse($this->loop->hasReadyWork());
        $this->assertSame(7, socket_write($this->peer, 'request'));
        $this->loop->tick();
        $this->loop->tick();
        $this->assertSame(['request'], $this->client->portions);
        $this->assertSame(3, $calls);
        $this->assertFalse($this->loop->hasReadyWork());

        $this->assertSame(5, socket_write($this->peer, 'again'));
        $received = $this->drainPeer();
        $this->loop->tick();
        $this->assertSame(3, $calls, 'both directions only enqueue owner work');
        $this->loop->tick();
        $this->assertSame(4, $calls, 'simultaneous READ and WRITE share one owner portion');
        $this->assertSame(['request', 'again'], $this->client->portions);
        $received .= $this->drainPeer();
        for ($pass = 0; $pass < 128 && $this->client->hasPendingWrite(); ++$pass) {
            $this->loop->tick();
            $received .= $this->drainPeer();
        }
        $this->assertFalse($this->client->hasPendingWrite());
        $this->assertSame($payload . 'tail', $received);
        $this->assertFalse($this->loop->hasReadyWork());
        $before = $calls;
        $this->loop->tick();
        $this->loop->tick();
        $this->assertSame($before, $calls, 'drained output must disarm WRITE');
    }

    /** Stopped input is discarded, but EOF still wins over queued output and a blocked WRITE. */
    public function testStoppedReadStillClosesOnEofWithQueuedOutput(): void
    {
        $calls = 0;
        $this->bind($calls);
        $this->client->send(str_repeat('x', 131_072));
        $this->loop->tick();
        $this->assertTrue($this->client->hasPendingWrite());
        $this->client->stopReading();
        $this->assertSame(7, socket_write($this->peer, 'discard'));
        $this->assertTrue(socket_shutdown($this->peer, 1));
        $this->loop->tick();
        $this->loop->tick();

        $this->assertTrue($this->client->shouldClose());
        $this->assertSame([], $this->client->portions);
        $this->assertSame(0, $this->loop->registeredCount());
        $this->assertFalse($this->loop->hasReadyWork());
    }

    /** Both explicit close intent and drain intent wake a client without incoming data. */
    public function testCloseOnceWrittenWaitsForActualSocketDrain(): void
    {
        $calls = 0;
        $this->bind($calls);
        $this->loop->tick();
        $payload = str_repeat('z', 131_072);
        $this->client->send($payload);
        $this->client->closeOnceWritten();
        $this->loop->tick();
        $this->assertFalse($this->client->shouldClose());

        $received = $this->drainPeer();
        for ($pass = 0; $pass < 128 && !$this->client->shouldClose(); ++$pass) {
            $this->loop->tick();
            $received .= $this->drainPeer();
        }
        $this->assertSame($payload, $received);
        $this->assertTrue($this->client->shouldClose());
        $this->assertSame(0, $this->loop->registeredCount());
        $this->assertFalse($this->loop->hasReadyWork());
    }

    /** Rebind cancels a native notification already turned into client work on the old loop. */
    public function testRebindCancelsQueuedOldOwnerAndPreservesUnreadInput(): void
    {
        $oldCalls = 0;
        $this->bind($oldCalls);
        $this->loop->tick();
        $this->assertSame(3, socket_write($this->peer, 'new'));
        $this->loop->tick();
        $this->assertTrue($this->loop->hasReadyWork());

        $otherLoop = new EventLoop();
        $newCalls = 0;
        try {
            $this->client->attachEventLoop($otherLoop, function () use (&$newCalls): void {
                ++$newCalls;
                $this->client->read();
            });
            $this->loop->tick();
            $this->assertSame(1, $oldCalls);
            $this->assertSame(0, $this->loop->registeredCount());
            $otherLoop->tick();
            $this->assertSame(1, $newCalls);
            $this->assertSame(['new'], $this->client->portions);
            $this->client->detachEventLoop();
            $this->client->detachEventLoop();
            $this->client->close();
            $this->client->close();
            $otherLoop->tick();
            $this->assertSame(1, $newCalls);
            $this->assertSame(0, $otherLoop->registeredCount());
            $this->assertFalse($otherLoop->hasReadyWork());
        } finally {
            $this->client->detachEventLoop();
            $otherLoop->close();
        }
    }

    /** Close cancels native-activated handlers before they can enqueue another owner portion. */
    public function testCloseCancelsNativeNotificationsAndCannotReattach(): void
    {
        $calls = 0;
        $this->bind($calls);
        $this->loop->tick();
        $this->assertSame(1, socket_write($this->peer, 'x'));
        $this->loop->createReadyWork($this->client->close(...))->markReady();
        $this->loop->tick();
        $this->client->attachEventLoop($this->loop, function () use (&$calls): void {
            ++$calls;
        });
        $this->client->send('late');
        $this->loop->tick();
        $this->assertSame(1, $calls);
        $this->assertSame(0, $this->loop->registeredCount());
        $this->assertFalse($this->loop->hasReadyWork());
    }

    /** An owner's original exception reaches its guard, outside the legacy native READ containment. */
    public function testOwnerFailureIsNotSwallowedByReadWatch(): void
    {
        $calls = 0;
        $failure = new HilosException('owner guard failure');
        $this->client->attachEventLoop($this->loop, static function () use (&$calls, $failure): void {
            if (++$calls > 1) {
                throw $failure;
            }
        });
        $this->loop->tick();
        $this->assertSame(1, socket_write($this->peer, 'x'));
        $this->loop->tick();
        try {
            $this->loop->tick();
            $this->fail('the client portion must reach the enclosing owner guard');
        } catch (HilosException $caught) {
            $this->assertSame($failure, $caught);
        }
    }

    /** Opens nonblocking socket ends with a small send buffer and an eight-byte read portion. */
    protected function setUp(): void
    {
        $this->previousEnv = Hilos::$env;
        $this->previousReadSize = getenv('SOCKET_READ_BUFFER_SIZE');
        Hilos::$env = new EnvAccessor();
        putenv('SOCKET_READ_BUFFER_SIZE=8');
        $sockets = [];
        $this->assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets));
        foreach ($sockets as $socket) {
            $this->assertTrue(socket_set_nonblock($socket));
        }
        $this->assertTrue(socket_set_option($sockets[0], SOL_SOCKET, SO_SNDBUF, 4096));
        $this->loop = new EventLoop();
        $this->client = new ClientEventLoopTestClient($sockets[0]);
        $this->peer = $sockets[1];
    }

    /** Releases native bindings before their descriptors and restores the process environment. */
    protected function tearDown(): void
    {
        $this->client->close();
        $this->loop->close();
        socket_close($this->peer);
        Hilos::$env = $this->previousEnv;
        putenv($this->previousReadSize === false ? 'SOCKET_READ_BUFFER_SIZE' : 'SOCKET_READ_BUFFER_SIZE=' . $this->previousReadSize);
    }

    /** @param int $calls Count of owner portions executed */
    private function bind(int &$calls): void
    {
        $this->client->attachEventLoop($this->loop, function () use (&$calls): void {
            ++$calls;
            $this->client->read();
            $this->client->write();
            if ($this->client->shouldClose()) {
                $this->client->close();
            }
        });
    }

    /** @return string All bytes already available on the peer, without waiting */
    private function drainPeer(): string
    {
        $received = '';
        while (true) {
            $bytes = @socket_read($this->peer, 65_536);
            if ($bytes === false) {
                $this->assertContains(socket_last_error($this->peer), [SOCKET_EAGAIN, SOCKET_EWOULDBLOCK]);
                socket_clear_error($this->peer);
                break;
            }
            if ($bytes === '') {
                break;
            }
            $received .= $bytes;
        }
        return $received;
    }
}

/** A protocol that records each complete read portion and writes through the production queue seam. */
final class ClientEventLoopTestClient extends AbstractClient
{
    /** @var list<string> Portions handed to the parser */
    public array $portions = [];

    /** @param string $bytes New output from the protocol */
    public function send(string $bytes): void
    {
        $this->queueWrite($bytes);
    }

    /** This protocol has no periodic work. */
    public function onTick(): void
    {
    }

    /** Consume exactly the portion gathered by the real client reader. */
    protected function processReadBuffer(): void
    {
        $this->portions[] = $this->readBuffer;
        $this->readBuffer = '';
    }

    /** This protocol owns no external close notification. */
    protected function onClose(): void
    {
    }
}
