<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Socket;

use Hilos\Socket\Client\AbstractClient;
use Hilos\Socket\Client\ClientInterface;
use Hilos\Socket\Exception\Base\ConnectionResetException;
use Hilos\Socket\Server\AbstractServer;
use Hilos\Socket\SocketException;
use Hilos\Socket\Transport\PlainSocketTransport;
use PHPUnit\Framework\TestCase;
use Socket;

/** A departing node stops browser input but writes its last delivery (HIL-1207). */
final class ClientCloseOnceWrittenTest extends TestCase
{
    /** @var array<int, Socket> Socket ends held by the test */
    private array $peers = [];

    protected function tearDown(): void
    {
        foreach ($this->peers as $peer) {
            socket_close($peer);
        }
        $this->peers = [];

        parent::tearDown();
    }

    /** Native enqueue keeps the application buffer and close intent alive until the drain acknowledgement. */
    public function testTlsCloseOnceWrittenWaitsForNativeDrain(): void
    {
        $fixture = new MutualTlsFixture();
        $authority = $fixture->issueAuthority('Drain CA');
        $trust = $fixture->trustFile($authority);
        $exchange = new TlsTestExchange($fixture,
            $fixture->issueNode('server', $authority), $fixture->issueNode('client', $authority), null, $trust);
        $client = new TlsTestClient($exchange->sockets['accepting'], $exchange->transports['accepting']);
        $exchange->bindClient('accepting', $client);
        try {
            $exchange->handshake();
            $client->queue('farewell');
            $client->closeOnceWritten();
            $client->write();
            $this->assertSame('farewell', $client->pendingOutbound());
            $this->assertFalse($client->shouldClose(), 'enqueue is not native drain');
            $exchange->runUntil(fn(): bool => $client->shouldClose() && $exchange->received['dialing'] === 'farewell');
            $this->assertSame('', $client->pendingOutbound());
            $this->assertSame('farewell', $exchange->received['dialing']);
            $this->assertSame(0, $client->drains, 'closing does not call the keep-alive drain hook');
        } finally {
            $exchange->close();
            $fixture->close();
        }
    }

    public function testAnEmptyBufferMarksTheClientForImmediateClose(): void
    {
        $client = $this->client();

        $this->assertFalse($client->hasPendingWrite());
        $client->closeOnceWritten();

        $this->assertTrue($client->shouldClose());
        $client->close();
    }

    public function testQueuedBytesAreWrittenBeforeTheServerDropsTheClient(): void
    {
        $client = $this->client();
        $peer = $this->peers[array_key_last($this->peers)];
        $client->queue('farewell');
        $client->closeOnceWritten();
        $server = new ClientCloseOnceWrittenTestServer();
        $server->seedClient($client);

        $this->assertTrue($client->hasPendingWrite());
        $this->assertFalse($client->shouldClose());
        $server->onTick();

        $this->assertSame('farewell', socket_read($peer, 8));
        $this->assertFalse($client->hasPendingWrite());
        $this->assertSame([], $server->getClients());
    }

    /** Stopped input is consumed without changing a previously buffered protocol fragment. */
    public function testStoppedReadingDoesNotProcessInput(): void
    {
        $client = $this->client();
        $peer = $this->peers[array_key_last($this->peers)];

        try {
            $this->assertSame(7, socket_write($peer, 'ignored'));
            $client->setReadBuffer('incomplete');
            $client->stopReading();
            $client->read();

            $this->assertSame(0, $client->processedReads);
            $this->assertSame('incomplete', $client->getReadBuffer());
            $transport = new PlainSocketTransport($client->getSocket());
            $this->assertSame('', $transport->read(1));
            $this->assertSame(0, socket_last_error($client->getSocket()));
            $this->assertFalse($transport->isEndOfStream());
            $this->assertFalse($client->shouldClose());
        } finally {
            $client->close();
        }
    }

    /** Would-block preserves queued output until the ordinary write closes the client. */
    public function testStoppedReadingWithoutInputStillWritesQueuedBytes(): void
    {
        $client = $this->client();
        $peer = $this->peers[array_key_last($this->peers)];

        try {
            $client->queue('farewell');
            $client->stopReading();
            $client->closeOnceWritten();
            $client->read();

            $this->assertFalse($client->shouldClose());
            $this->assertTrue($client->hasPendingWrite());
            $this->assertSame(0, $client->processedReads);

            $client->write();

            $this->assertSame('farewell', socket_read($peer, 8));
            $this->assertFalse($client->hasPendingWrite());
            $this->assertTrue($client->shouldClose());
        } finally {
            $client->close();
        }
    }

    /** EOF marks a stopped client for close even while output is still queued. */
    public function testStoppedReadingClosesAtEofWithoutWaitingForQueuedBytes(): void
    {
        $client = $this->client();
        $peer = $this->peers[array_key_last($this->peers)];

        try {
            $client->queue('farewell');
            $client->stopReading();
            $client->closeOnceWritten();
            $this->closePeer($peer);

            $client->read();

            $this->assertTrue($client->shouldClose());
            $this->assertTrue($client->hasPendingWrite());
            $this->assertSame(0, $client->processedReads);
        } finally {
            $client->close();
        }
    }

    /** Bytes before EOF are discarded without appending to or parsing the old fragment. */
    public function testStoppedReadingDiscardsDataBeforeEofClosesTheClient(): void
    {
        $client = $this->client();
        $peer = $this->peers[array_key_last($this->peers)];

        try {
            $this->assertSame(7, socket_write($peer, 'ignored'));
            $client->setReadBuffer('incomplete');
            $client->queue('farewell');
            $client->stopReading();
            $client->closeOnceWritten();
            $this->closePeer($peer);

            $client->read();

            $this->assertTrue($client->shouldClose());
            $this->assertTrue($client->hasPendingWrite());
            $this->assertSame(0, $client->processedReads);
            $this->assertSame('incomplete', $client->getReadBuffer());
            $this->assertSame('', (new PlainSocketTransport($client->getSocket()))->read(1));
        } finally {
            $client->close();
        }
    }

    /** A real reset after input keeps its exception and bypasses the output drain wait. */
    public function testStoppedReadingDiscardsDataBeforeResetClosesTheClient(): void
    {
        $client = $this->client();
        $peer = $this->peers[array_key_last($this->peers)];

        try {
            $this->assertSame(7, socket_write($peer, 'ignored'));
            $this->assertSame(6, socket_write($client->getSocket(), 'unread'));
            $client->setReadBuffer('incomplete');
            $client->queue('farewell');
            $client->stopReading();
            $client->closeOnceWritten();
            $this->closePeer($peer);

            try {
                $client->read();
                $this->fail('The reset after discarded input must raise');
            } catch (ConnectionResetException) {
                $this->assertTrue($client->shouldClose());
                $this->assertTrue($client->hasPendingWrite());
                $this->assertSame(0, $client->processedReads);
                $this->assertSame('incomplete', $client->getReadBuffer());
            }
        } finally {
            $client->close();
        }
    }

    /**
     * @return ClientCloseOnceWrittenTestClient Client on a nonblocking socket pair
     */
    private function client(): ClientCloseOnceWrittenTestClient
    {
        $pair = [];
        $this->assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        $this->assertTrue(socket_set_nonblock($pair[0]));
        $this->peers[spl_object_id($pair[1])] = $pair[1];

        return new ClientCloseOnceWrittenTestClient($pair[0]);
    }

    /** @param Socket $peer Test-owned end to close before reading EOF or reset */
    private function closePeer(Socket $peer): void
    {
        socket_close($peer);
        unset($this->peers[spl_object_id($peer)]);
    }
}

/** Server used to drive the real client write and drop path. */
final class ClientCloseOnceWrittenTestServer extends AbstractServer
{
    public function __construct()
    {
        parent::__construct('127.0.0.1', 0);
    }

    /** @param ClientCloseOnceWrittenTestClient $client Connected client to tick */
    public function seedClient(ClientCloseOnceWrittenTestClient $client): void
    {
        $this->addClient($client);
    }

    /** @return string Server name for failures */
    public function getServerName(): string
    {
        return 'client-close-once-written-test';
    }

    protected function onStart(): void
    {
    }

    /**
     * @param resource $socket Unused accepted socket
     * @return ClientInterface Never returned
     * @throws SocketException Always, since this test does not accept clients
     */
    protected function onCreateClient($socket): ClientInterface
    {
        throw new SocketException('the close-once-written test accepts no clients');
    }
}

/** Client that records read processing and accepts queued output. */
final class ClientCloseOnceWrittenTestClient extends AbstractClient
{
    public int $processedReads = 0;

    /** @param string $bytes Bytes to queue for the peer */
    public function queue(string $bytes): void
    {
        $this->writeBuffer .= $bytes;
    }

    /** @param string $bytes Protocol fragment already buffered before input stops */
    public function setReadBuffer(string $bytes): void
    {
        $this->readBuffer = $bytes;
    }

    /** @return string Protocol fragment retained after stopped reads */
    public function getReadBuffer(): string
    {
        return $this->readBuffer;
    }

    public function onTick(): void
    {
    }

    protected function processReadBuffer(): void
    {
        $this->processedReads++;
        $this->readBuffer = '';
    }

    protected function onClose(): void
    {
    }
}
