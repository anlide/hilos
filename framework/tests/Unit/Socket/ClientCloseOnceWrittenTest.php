<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Socket;

use Hilos\Socket\Client\AbstractClient;
use Hilos\Socket\Client\ClientInterface;
use Hilos\Socket\Server\AbstractServer;
use Hilos\Socket\SocketException;
use PHPUnit\Framework\TestCase;
use Socket;

/** A departing node stops browser input but writes its last delivery (HIL-1207). */
final class ClientCloseOnceWrittenTest extends TestCase
{
    /** @var list<Socket> Socket ends held by the test */
    private array $peers = [];

    protected function tearDown(): void
    {
        foreach ($this->peers as $peer) {
            socket_close($peer);
        }
        $this->peers = [];

        parent::tearDown();
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

    public function testStoppedReadingDoesNotProcessInput(): void
    {
        $client = $this->client();
        $peer = $this->peers[array_key_last($this->peers)];
        $this->assertSame(7, socket_write($peer, 'ignored'));

        $client->stopReading();
        $client->read();

        $this->assertSame(0, $client->processedReads);
        $client->close();
    }

    /**
     * @return ClientCloseOnceWrittenTestClient Client on a nonblocking socket pair
     */
    private function client(): ClientCloseOnceWrittenTestClient
    {
        $pair = [];
        $this->assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        $this->assertTrue(socket_set_nonblock($pair[0]));
        $this->peers[] = $pair[1];

        return new ClientCloseOnceWrittenTestClient($pair[0]);
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
        $this->clients[] = $client;
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
