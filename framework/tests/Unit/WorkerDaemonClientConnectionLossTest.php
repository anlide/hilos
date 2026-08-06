<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Socket\Worker\DaemonConnectionState;
use Hilos\Socket\Worker\DTO\AgentStartDTO;
use Hilos\Socket\Worker\WorkerDaemonClient;
use PHPUnit\Framework\TestCase;
use Socket;

/**
 * Unit tests for the terminal state of the worker side of the daemon connection.
 */
final class WorkerDaemonClientConnectionLossTest extends TestCase
{
    /** @var array<int, Socket> Daemon ends of the socket pairs opened by a test */
    private array $daemonEnds = [];

    public function tearDown(): void
    {
        foreach ($this->daemonEnds as $daemonEnd) {
            socket_close($daemonEnd);
        }
        $this->daemonEnds = [];

        parent::tearDown();
    }

    public function testEofMovesConnectionToLostState(): void
    {
        $client = $this->connectedClient($daemonEnd);
        socket_close($daemonEnd);
        array_pop($this->daemonEnds);

        $client->read();

        $this->assertFalse($client->isConnected());
        $this->assertTrue($client->isConnectionLost());
        $this->assertSame(DaemonConnectionState::LOST, $client->currentState());
    }

    public function testCheckConnectionDoesNotReviveLostConnection(): void
    {
        $client = $this->connectedClient($daemonEnd);
        socket_close($daemonEnd);
        array_pop($this->daemonEnds);

        $client->read();
        $client->checkConnection();

        $this->assertFalse($client->isConnected());
        $this->assertTrue($client->isConnectionLost());
    }

    public function testMessagesReceivedBeforeEofStayInQueue(): void
    {
        $client = $this->connectedClient($daemonEnd);
        $message = json_encode((new AgentStartDTO('unit-liveness-agent'))->toArray()) . "\n";
        socket_write($daemonEnd, $message);
        socket_close($daemonEnd);
        array_pop($this->daemonEnds);

        $client->read();
        $client->read();

        $this->assertTrue($client->isConnectionLost());
        $queued = $client->getNextMessage();
        $this->assertInstanceOf(AgentStartDTO::class, $queued);
        $this->assertSame('unit-liveness-agent', $queued->agentId);
    }

    public function testWriteAfterLossIsNoOp(): void
    {
        $client = $this->connectedClient($daemonEnd);
        socket_close($daemonEnd);
        array_pop($this->daemonEnds);

        $client->read();
        $client->send(new AgentStartDTO('unit-liveness-agent'));
        $client->write();

        $this->assertTrue($client->isConnectionLost());
    }

    /**
     * Builds a client sitting on a live socket pair, as if connect() had succeeded.
     *
     * @param ?Socket $daemonEnd Receives the daemon end of the pair
     * @return WorkerDaemonClientConnectionLossTestClient Client on the worker end
     */
    private function connectedClient(?Socket &$daemonEnd): WorkerDaemonClientConnectionLossTestClient
    {
        $pair = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        [$workerEnd, $daemonEnd] = $pair;
        socket_set_nonblock($workerEnd);
        $this->daemonEnds[] = $daemonEnd;

        $client = new WorkerDaemonClientConnectionLossTestClient();
        $client->adoptConnectedSocket($workerEnd);

        return $client;
    }
}

/**
 * Client exposing the connection state and accepting a ready-made socket.
 *
 * The real connect() dials the daemon; a unit test instead hands the client one
 * end of a socket pair whose other end it can close on demand.
 */
final class WorkerDaemonClientConnectionLossTestClient extends WorkerDaemonClient
{
    /**
     * Adopts an already established socket as the daemon connection.
     *
     * @param Socket $socket Worker end of a connected socket pair
     */
    public function adoptConnectedSocket(Socket $socket): void
    {
        $this->socket = $socket;
        $this->state = DaemonConnectionState::CONNECTED;
    }

    /**
     * @return DaemonConnectionState Current connection state
     */
    public function currentState(): DaemonConnectionState
    {
        return $this->state;
    }
}
