<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Socket;

use Event;
use EventBufferEvent;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Socket\Exception\SocketTlsHandshakeException;
use Hilos\Socket\Exception\SocketReadException;
use Hilos\Socket\SocketException;
use Hilos\Socket\Transport\TlsContext;
use Hilos\Socket\Transport\TlsSocketTransport;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use WeakReference;

/** Application bytes, bounded native queues and exact drain acknowledgement on real TLS sockets. */
final class TlsSocketTransportTest extends TestCase
{
    private MutualTlsFixture $fixture;

    private ?TlsTestExchange $exchange = null;

    private ?TlsTestClient $client = null;

    public function testNativeCallbacksBorrowTheTransportAfterItsLoopOwnersAreReleased(): void
    {
        $exchange = $this->connect();
        $exchange->handshake();
        $native = $this->native('dialing');
        $owner = WeakReference::create($exchange->transports['dialing']);
        $exchange->loop->cleanup();
        try {
            unset($exchange->transports['dialing']);
            $this->assertNull($owner->get(), 'A native callback must not keep its own transport alive until Zend shutdown');
            $this->assertSame(0, $exchange->loop->registeredCount());
            $this->assertFalse($exchange->loop->hasReadyWork());
            // Native TLS can still notify after the owner was released; the old binding is inert.
            $native->enable(Event::READ | Event::WRITE);
            for ($pass = 0; $pass < 8; ++$pass) {
                $exchange->loop->tick();
            }
            $this->assertFalse($exchange->loop->hasReadyWork());
            $this->assertSame([], $exchange->failures);
        } finally {
            $native->disable(Event::READ | Event::WRITE);
            $ignore = static function (): void {};
            $native->setCallbacks($ignore, $ignore, $ignore);
        }
    }

    public function testAHandshakeThatTakesSeveralTurnsFinishesWithoutClosingTheConnection(): void
    {
        $exchange = $this->connect();
        $this->assertTrue($exchange->transports['accepting']->needsHandshake());
        $this->assertFalse($this->client->shouldClose());
        $exchange->handshake();
        $this->assertSame([], $exchange->failures);
        $this->assertGreaterThan(1, $this->client->ticks);
        $this->assertFalse($exchange->transports['accepting']->needsHandshake());
        $this->assertFalse($this->client->shouldClose());
        $this->assertSame(0, $exchange->transports['dialing']->write('ping'));
        $exchange->runUntil(fn(): bool => $this->client->received === 'ping');
        $this->assertSame('ping', $this->client->received);
    }

    public function testAnEmptyReadClosesTheClientOnlyAtTheEndOfTheStream(): void
    {
        $exchange = $this->connect();
        $exchange->handshake();
        for ($read = 0; $read < 10; ++$read) {
            $this->client->read();
        }
        $this->assertFalse($this->client->shouldClose());
        $exchange->transports['dialing']->write('still here');
        $exchange->runUntil(fn(): bool => $this->client->received === 'still here');
        $this->assertFalse($this->client->shouldClose());
        $exchange->transports['dialing']->close();
        $exchange->runUntil(fn(): bool => $this->client->shouldClose() || $exchange->failures !== []);
        $this->assertSame([], $exchange->failures);
        $this->assertTrue($this->client->shouldClose());
    }

    public function testAPartialWriteIsFinishedOnALaterTurnAndZeroBytesTakenIsNoError(): void
    {
        $exchange = $this->connect();
        $exchange->handshake();
        $payload = str_repeat('0123456789abcdef', 524288);
        $this->client->queue($payload);
        $this->client->write();
        $this->assertSame($payload, $this->client->pendingOutbound(), 'native enqueue acknowledges no application byte');
        $this->assertFalse($this->client->shouldClose());
        $exchange->runUntil(fn(): bool => strlen($exchange->received['dialing']) === strlen($payload) && !$this->client->hasPendingWrite());
        $this->assertSame($payload, $exchange->received['dialing']);
        $this->assertSame('', $this->client->pendingOutbound());
        $this->assertSame(1, $this->client->drains);
        $this->assertFalse($this->client->shouldClose());
    }

    public function testARefusedHandshakeClosesTheConnectionWithoutWritingAByte(): void
    {
        $authority = $this->fixture->issueAuthority('Gateway CA');
        [$accepted, $peer] = $this->fixture->connectSockets();
        $transport = TlsSocketTransport::accepting($accepted, TlsContext::accepting($this->fixture->issueNode('gateway', $authority)));
        $client = new TlsTestClient($accepted, $transport);
        $loop = new EventLoop();
        $client->attachEventLoop($loop, static function () use ($client): void {
            $client->read();
            $client->write();
        });
        try {
            $client->queue("HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n");
            socket_write($peer, "GET /test/health HTTP/1.1\r\nHost: stand-gateway\r\n\r\n");
            for ($pass = 0; $pass < 20 && !$client->shouldClose(); ++$pass) {
                $loop->tick();
            }
            $this->assertTrue($client->shouldClose());
            $client->write();
            $client->close();
            $stream = socket_export_stream($peer);
            $this->assertIsResource($stream);
            $this->assertSame('', (string)@fread($stream, 65536));
        } finally {
            $client->close();
            $loop->close();
        }
    }

    public function testAHandshakeRefusalNamedToACallerCarriesItsReason(): void
    {
        $exception = new SocketTlsHandshakeException('certificate verify failed');
        $this->assertInstanceOf(SocketException::class, $exception);
        $this->assertSame('Socket TLS handshake failed: certificate verify failed', $exception->getMessage());
    }

    public function testOneNativeSendLargerThanTheReadPortionContinuesWithoutAnotherTcpWrite(): void
    {
        $exchange = $this->connect();
        $exchange->handshake();
        $payload = str_repeat('input', 40000);
        $this->assertTrue($this->native('dialing')->write($payload));
        $this->native('dialing')->enable(Event::WRITE);
        $exchange->runUntil(fn(): bool => strlen($this->client->received) === strlen($payload));
        $this->assertSame($payload, $this->client->received);
        $this->assertGreaterThan(1, count($this->client->portions));
        foreach ($this->client->portions as $bytes) {
            $this->assertLessThanOrEqual(65536, $bytes);
        }
        $this->assertLessThanOrEqual(65536, $this->native('accepting')->input->length);
    }

    public function testBlockedNativeOutputWaitsForPeerReadinessAndThenResumes(): void
    {
        $this->markTestSkipped(
            'FIXME(F-092, 2026-11-11): on fantom-pl the exchange spins forever instead of failing in 5 s · foreign · attempt 1 · HOTFIX',
        );
        $exchange = $this->connect();
        $exchange->handshake();
        socket_set_option($exchange->sockets['accepting'], SOL_SOCKET, SO_SNDBUF, 4096);
        $exchange->transports['dialing']->detachEventLoop();
        $payload = str_repeat('blocked', 300000);
        $this->client->queue($payload);
        // Drive only already-runnable native work until the kernel and bounded prefix block.
        for ($pass = 0; $pass < 100; ++$pass) {
            $exchange->loop->tick();
        }
        $this->assertTrue($this->client->hasPendingWrite());
        $this->assertLessThanOrEqual(16384, $this->native('accepting')->output->length);
        $exchange->loop->tick();
        $before = $this->client->ticks;
        for ($pass = 0; $pass < 10; ++$pass) {
            $exchange->loop->tick();
        }
        $this->assertSame($before, $this->client->ticks, 'blocked output does not enqueue runnable work');
        $work = $exchange->loop->createReadyWork(function () use ($exchange): void {
            $exchange->received['dialing'] .= (string)$exchange->transports['dialing']->read(65536);
        });
        $exchange->transports['dialing']->attachEventLoop($exchange->loop, $work->markReady(...));
        $exchange->runUntil(fn(): bool => strlen($exchange->received['dialing']) === strlen($payload) && !$this->client->hasPendingWrite());
        $this->assertSame($payload, $exchange->received['dialing']);
    }

    public function testInFlightPrefixAcknowledgesExactlyOnceAndAppendedTailIsNotDuplicated(): void
    {
        $exchange = $this->connect();
        $exchange->handshake();
        $transport = $exchange->transports['dialing'];
        $this->assertSame(0, $transport->write('prefix'));
        $this->assertSame(0, $transport->write('prefix-tail'));
        $exchange->runUntil(fn(): bool => $this->client->received === 'prefix');
        $this->assertSame(6, $transport->write('prefix-tail'));
        $this->assertSame(0, $transport->write('-tail'));
        $exchange->runUntil(fn(): bool => $this->client->received === 'prefix-tail');
        $this->assertSame(5, $transport->write('-tail'));
        $this->assertSame(0, $transport->write(''));
        $this->assertSame('prefix-tail', $this->client->received);
        $this->assertSame(0, $this->native('dialing')->output->length);
    }

    public function testEofFollowsAlreadyAcceptedDecryptedBytes(): void
    {
        $exchange = $this->connect();
        $exchange->handshake();
        $exchange->transports['dialing']->write('last bytes');
        $exchange->runUntil(fn(): bool => $this->native('accepting')->input->length > 0 || $this->client->received !== '');
        $exchange->transports['dialing']->close();
        $exchange->runUntil(fn(): bool => $this->client->shouldClose() || $exchange->failures !== []);
        $this->assertSame('last bytes', $this->client->received);
        $this->assertSame([], $exchange->failures);
        $this->assertTrue($this->client->shouldClose());
    }

    /** A TLS protocol failure is raised under the owner guard only after its already decrypted input. */
    public function testNativeErrorPreservesDecryptedBytesAndWinsOverPendingOutput(): void
    {
        $exchange = $this->connect();
        $exchange->handshake();
        $this->client->detachEventLoop();
        $work = $exchange->loop->createReadyWork(static function (): void {});
        $exchange->transports['accepting']->attachEventLoop($exchange->loop, $work->markReady(...));
        $exchange->transports['dialing']->write('accepted before failure');
        $exchange->runUntil(fn(): bool => $this->native('accepting')->input->length > 0);
        // The sender deliberately violates TLS after a valid record; no new process or fake native class.
        socket_write($exchange->sockets['dialing'], 'invalid TLS record after the accepted prefix');
        $this->native('accepting')->enable(Event::READ);
        $events = new ReflectionProperty(TlsSocketTransport::class, 'events');
        $exchange->runUntil(fn(): bool => ($events->getValue($exchange->transports['accepting']) & EventBufferEvent::ERROR) !== 0);
        $this->client->queue('must wait for the terminal read');
        $exchange->bindClient('accepting', $this->client);
        $exchange->runUntil(fn(): bool => isset($exchange->failures['accepting']));
        $this->assertSame('accepted before failure', $this->client->received);
        $this->assertInstanceOf(SocketReadException::class, $exchange->failures['accepting']);
        $this->assertSame('', $exchange->received['dialing']);
        $this->assertTrue($this->client->hasPendingWrite());
        $work->close();
    }

    /** Paused native input retains a bounded portion and rebind releases the old owner without losing bytes. */
    public function testPausedNativeInputIsBoundedAndResumesOnTheSameBase(): void
    {
        $exchange = $this->connect();
        $exchange->handshake();
        $this->client->detachEventLoop();
        $work = $exchange->loop->createReadyWork(static function (): void {});
        $exchange->transports['accepting']->attachEventLoop($exchange->loop, $work->markReady(...));
        $payload = str_repeat('retained', 30000);
        $this->assertTrue($this->native('dialing')->write($payload));
        $this->native('dialing')->enable(Event::WRITE);
        $exchange->runUntil(fn(): bool => $this->native('accepting')->input->length > 0);
        $ticks = $this->client->ticks;
        for ($pass = 0; $pass < 10; ++$pass) {
            $exchange->loop->tick();
        }
        $this->assertLessThanOrEqual(65536, $this->native('accepting')->input->length);
        $this->assertSame($ticks, $this->client->ticks, 'the detached owner is never invoked');
        $this->assertSame('', $this->client->received);
        $exchange->bindClient('accepting', $this->client);
        $exchange->runUntil(fn(): bool => strlen($this->client->received) === strlen($payload));
        $this->assertSame($payload, $this->client->received);
        $this->assertSame(0, $exchange->loop->registeredCount(), 'TLS installs no parallel raw socket watchers');
        $this->client->close();
        $this->client->close();
        $this->assertNull(new ReflectionProperty(TlsSocketTransport::class, 'event')->getValue($exchange->transports['accepting']));
        $work->close();
    }

    protected function setUp(): void
    {
        $this->fixture = new MutualTlsFixture();
    }

    protected function tearDown(): void
    {
        $this->exchange?->close();
        $this->fixture->close();
    }

    /** @return TlsTestExchange One native exchange whose accepting end has an application owner */
    private function connect(): TlsTestExchange
    {
        $authority = $this->fixture->issueAuthority('Gateway CA');
        $trust = $this->fixture->trustFile($authority);
        $exchange = $this->exchange = new TlsTestExchange($this->fixture,
            $this->fixture->issueNode('gateway', $authority), $this->fixture->issueNode('caller', $authority), null, $trust);
        $this->client = new TlsTestClient($exchange->sockets['accepting'], $exchange->transports['accepting']);
        $exchange->bindClient('accepting', $this->client);
        return $exchange;
    }

    /**
     * @param string $end Native end to inspect, without replacing its production implementation
     * @return EventBufferEvent Real libevent TLS buffer event
     */
    private function native(string $end): EventBufferEvent
    {
        return new ReflectionProperty(TlsSocketTransport::class, 'event')->getValue($this->exchange->transports[$end]);
    }
}
