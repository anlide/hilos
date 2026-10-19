<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Socket;

use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Socket\Client\AbstractClient;
use Hilos\Socket\Exception\Base\ConnectionResetException;
use Hilos\Socket\Transport\SocketTransportInterface;
use PHPUnit\Framework\TestCase;
use Socket;

/** A master read collects one bounded portion, including short transport reads (HIL-1527). */
final class ClientReadPortionTest extends TestCase
{
    private ?EnvAccessor $previousEnv = null;

    /** @var array<int, Socket> Socket ends still owned by the test */
    private array $ownedSockets = [];

    /** Install the read portion used by the upload path. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousEnv = Hilos::$env;
        Hilos::$env = new EnvAccessor();
        putenv('SOCKET_READ_BUFFER_SIZE=65536');
    }

    /** Release sockets and restore the environment accessor. */
    protected function tearDown(): void
    {
        foreach ($this->ownedSockets as $socket) {
            socket_close($socket);
        }
        Hilos::$env = $this->previousEnv;
        putenv('SOCKET_READ_BUFFER_SIZE');

        parent::tearDown();
    }

    /** A queued stream larger than two portions stays bounded on each call. */
    public function testEachReadTakesAtMostOnePortion(): void
    {
        [$clientSocket, $peerSocket] = $this->socketPair();
        $payload = str_repeat('abc', 50_000);
        $this->assertSame(150_000, socket_write($peerSocket, $payload));
        $client = new ClientReadPortionTestClient($clientSocket);

        $client->read();
        $this->assertSame([substr($payload, 0, 65_536)], $client->processed);
        $client->read();
        $this->assertSame(substr($payload, 65_536, 65_536), $client->processed[1]);
        $client->read();
        $this->assertSame(substr($payload, 131_072), $client->processed[2]);
        $this->assertSame([65_536, 65_536, 18_928], array_map(strlen(...), $client->processed));
        $this->assertSame($payload, implode('', $client->processed));
        $this->assertFalse($client->shouldClose());
    }

    /** Short reads of decrypted records use only the remaining portion budget. */
    public function testShortTransportReadsAreCollectedAndParsedOnce(): void
    {
        [$clientSocket] = $this->socketPair();
        $lengths = [];
        $chunks = [str_repeat('a', 16_384), str_repeat('b', 16_384), str_repeat('c', 32_768)];
        $transport = $this->createMock(SocketTransportInterface::class);
        $transport->expects($this->exactly(3))->method('read')->willReturnCallback(
            static function (int $length) use (&$lengths, &$chunks): string {
                $lengths[] = $length;
                return array_shift($chunks);
            },
        );
        $client = new ClientReadPortionTestClient($clientSocket, $transport);

        $client->read();

        $this->assertSame([65_536, 49_152, 32_768], $lengths);
        $this->assertSame([str_repeat('a', 16_384) . str_repeat('b', 16_384) . str_repeat('c', 32_768)], $client->processed);
        $this->assertFalse($client->shouldClose());
    }

    /** An empty TLS record ends this portion without closing a live stream. */
    public function testEmptyTransportReadWithoutEofLeavesConnectionOpen(): void
    {
        [$clientSocket] = $this->socketPair();
        $transport = $this->createMock(SocketTransportInterface::class);
        $transport->expects($this->exactly(2))->method('read')->willReturn('decrypted', '');
        $transport->expects($this->once())->method('isEndOfStream')->willReturn(false);
        $client = new ClientReadPortionTestClient($clientSocket, $transport);

        $client->read();

        $this->assertSame(['decrypted'], $client->processed);
        $this->assertFalse($client->shouldClose());
    }

    /** EOF follows processing of the bytes preceding it in the same call. */
    public function testDataIsProcessedBeforeEofClosesTheClient(): void
    {
        [$clientSocket, $peerSocket] = $this->socketPair();
        $this->assertSame(7, socket_write($peerSocket, 'payload'));
        $this->closeOwnedSocket($peerSocket);
        $client = new ClientReadPortionTestClient($clientSocket);

        $client->read();

        $this->assertSame(['payload'], $client->processed);
        $this->assertFalse($client->closedDuringProcessing);
        $this->assertTrue($client->shouldClose());
    }

    /** A real reset retains its socket error through processing of preceding bytes. */
    public function testDataIsProcessedBeforeTheOriginalSocketErrorIsRaised(): void
    {
        [$clientSocket, $peerSocket] = $this->socketPair();
        $this->assertSame(7, socket_write($peerSocket, 'payload'));
        $this->assertSame(6, socket_write($clientSocket, 'unread'));
        $this->closeOwnedSocket($peerSocket);
        $client = new ClientReadPortionTestClient($clientSocket);

        try {
            $client->read();
            $this->fail('The reset after the payload must raise');
        } catch (ConnectionResetException) {
            $this->assertSame(['payload'], $client->processed);
            $this->assertSame(SOCKET_ECONNRESET, $client->socketErrorDuringProcessing);
            $this->assertFalse($client->closedDuringProcessing);
            $this->assertTrue($client->shouldClose());
        }
    }

    /** Would-block neither parses an empty buffer nor closes the connection. */
    public function testEmptySocketLeavesTheClientUnchanged(): void
    {
        [$clientSocket] = $this->socketPair();
        $client = new ClientReadPortionTestClient($clientSocket);

        $client->read();

        $this->assertSame([], $client->processed);
        $this->assertFalse($client->shouldClose());
    }

    /**
     * @return array{Socket, Socket} Connected socket ends owned by the test
     */
    private function socketPair(): array
    {
        $pair = [];
        $this->assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        foreach ($pair as $socket) {
            $this->ownedSockets[spl_object_id($socket)] = $socket;
        }
        $this->assertTrue(socket_set_nonblock($pair[0]));

        return $pair;
    }

    /**
     * @param Socket $socket Test-owned end to close
     */
    private function closeOwnedSocket(Socket $socket): void
    {
        socket_close($socket);
        unset($this->ownedSockets[spl_object_id($socket)]);
    }
}

/** Records each parsed portion and the connection state at that point. */
final class ClientReadPortionTestClient extends AbstractClient
{
    /** @var list<string> Portions handed to the parser */
    public array $processed = [];

    public bool $closedDuringProcessing = false;

    public int $socketErrorDuringProcessing = 0;

    /** This client has no periodic work. */
    public function onTick(): void
    {
    }

    /** Consume the entire portion while recording the pending socket error. */
    protected function processReadBuffer(): void
    {
        $this->processed[] = $this->readBuffer;
        $this->readBuffer = '';
        $this->closedDuringProcessing = $this->shouldClose();
        $this->socketErrorDuringProcessing = socket_last_error($this->socket);
    }

    /** Test sockets are released by the test itself. */
    protected function onClose(): void
    {
    }
}
