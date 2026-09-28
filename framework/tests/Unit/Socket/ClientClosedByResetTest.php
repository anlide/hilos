<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Socket;

use Hilos\Socket\Client\AbstractClient;
use Hilos\Socket\Client\ClientInterface;
use Hilos\Socket\Server\AbstractServer;
use Hilos\Socket\SocketException;
use Hilos\Socket\Transport\PlainSocketTransport;
use Hilos\Utils\ClientReadFailureLog;
use PHPUnit\Framework\TestCase;
use Socket;

/**
 * A reset read must not prevent the server from announcing the client's close (HIL-1162).
 */
final class ClientClosedByResetTest extends TestCase
{
    /** @var array<int, Socket> Socket ends still owned by the test */
    private array $ownedSockets = [];

    protected function tearDown(): void
    {
        foreach ($this->ownedSockets as $socket) {
            socket_close($socket);
        }
        $this->ownedSockets = [];
        ClientReadFailureLog::reset();

        parent::tearDown();
    }

    public function testAClientWhosePeerIsResetIsAnnouncedAsClosed(): void
    {
        [$clientSocket, $peerSocket] = $this->socketPair();
        $this->assertSame(7, socket_write($clientSocket, "unread\n"));
        $this->closeOwnedSocket($peerSocket);

        $client = new ClientClosedByResetTestClient($this->giveSocketToClient($clientSocket));
        $server = new ClientClosedByResetTestServer();
        $server->seedClient($client);

        $server->onTick();

        $this->assertSame(1, $client->closeAnnouncements);
        $this->assertSame([], $server->getClients());
    }

    public function testAClientClosedCleanlyAfterAnotherSocketWasResetIsAnnounced(): void
    {
        [$resetSocket, $resetPeer] = $this->socketPair();
        $this->assertSame(7, socket_write($resetSocket, "unread\n"));
        $this->closeOwnedSocket($resetPeer);
        $this->assertFalse((new PlainSocketTransport($resetSocket))->read(1024));
        $this->closeOwnedSocket($resetSocket);

        [$clientSocket, $peerSocket] = $this->socketPair();
        $this->closeOwnedSocket($peerSocket);
        $client = new ClientClosedByResetTestClient($this->giveSocketToClient($clientSocket));
        $server = new ClientClosedByResetTestServer();
        $server->seedClient($client);

        $server->onTick();

        $this->assertSame(1, $client->closeAnnouncements);
        $this->assertSame([], $server->getClients());
    }

    public function testAClientClosedCleanlyIsAnnouncedOnce(): void
    {
        [$clientSocket, $peerSocket] = $this->socketPair();
        $this->closeOwnedSocket($peerSocket);
        $client = new ClientClosedByResetTestClient($this->giveSocketToClient($clientSocket));
        $server = new ClientClosedByResetTestServer();
        $server->seedClient($client);

        $server->onTick();
        $client->close();

        $this->assertSame(1, $client->closeAnnouncements);
        $this->assertSame([], $server->getClients());
    }

    /**
     * @return array{0: Socket, 1: Socket} Connected ends owned by the test
     */
    private function socketPair(): array
    {
        $pair = [];
        $this->assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        $this->ownedSockets[spl_object_id($pair[0])] = $pair[0];
        $this->ownedSockets[spl_object_id($pair[1])] = $pair[1];
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

    /**
     * @param Socket $socket End transferred to the client
     * @return Socket Client-owned end
     */
    private function giveSocketToClient(Socket $socket): Socket
    {
        unset($this->ownedSockets[spl_object_id($socket)]);

        return $socket;
    }
}

/** Server that holds the client's end of a real socket pair. */
final class ClientClosedByResetTestServer extends AbstractServer
{
    public function __construct()
    {
        parent::__construct('127.0.0.1', 0);
    }

    /**
     * @param ClientClosedByResetTestClient $client Client to read on the next tick
     */
    public function seedClient(ClientClosedByResetTestClient $client): void
    {
        $this->clients[] = $client;
    }

    /**
     * @return string Server name used for a failed read's journal line
     */
    public function getServerName(): string
    {
        return 'client-closed-by-reset-test';
    }

    protected function onStart(): void
    {
    }

    /**
     * @param resource $socket Client socket
     * @return ClientInterface Never returned; this server accepts no connection
     * @throws SocketException Always
     */
    protected function onCreateClient($socket): ClientInterface
    {
        throw new SocketException('the client closed by reset test accepts no connection');
    }
}

/** Client that counts how often its closed connection is announced. */
final class ClientClosedByResetTestClient extends AbstractClient
{
    /** @var int Number of close announcements */
    public int $closeAnnouncements = 0;

    public function onTick(): void
    {
    }

    protected function processReadBuffer(): void
    {
        $this->readBuffer = '';
    }

    protected function onClose(): void
    {
        $this->closeAnnouncements++;
    }
}
