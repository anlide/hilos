<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Daemon\BaseManager;
use Hilos\Socket\Exception\Base\BrokenPipeException;
use Hilos\Socket\Exception\Base\ConnectionResetException;
use Hilos\Socket\Worker\DaemonConnectionState;
use Hilos\Socket\Worker\DTO\AgentStartDTO;
use Hilos\Socket\Worker\WorkerDaemonClient;
use ErrorException;
use PHPUnit\Framework\TestCase;
use Socket;

/**
 * Unit tests for the terminal state of the worker side of the daemon connection.
 */
final class WorkerDaemonClientConnectionLossTest extends TestCase
{
    /** @var array<int, Socket> Daemon ends of the socket pairs opened by a test */
    private array $daemonEnds = [];

    /** Error reporting level to restore with the error handler a test installed, or null while none is. */
    private ?int $reportingBeforeHandler = null;

    public function tearDown(): void
    {
        foreach ($this->daemonEnds as $daemonEnd) {
            socket_close($daemonEnd);
        }
        $this->daemonEnds = [];
        if ($this->reportingBeforeHandler !== null) {
            restore_error_handler();
            error_reporting($this->reportingBeforeHandler);
            $this->reportingBeforeHandler = null;
        }

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
     * A write into a peer that has already gone is a broken pipe, and it arrives as that.
     *
     * The handler is the one a running worker installs: without the suppression it turns
     * the warning into an ErrorException before the client can read the error code.
     */
    public function testWriteIntoAClosedPeerLosesTheConnectionAsABrokenPipe(): void
    {
        $client = $this->connectedClient($daemonEnd);
        $client->send(new AgentStartDTO('unit-broken-pipe'));
        $this->assertTrue($client->hasPendingWrite());
        socket_close($daemonEnd);
        array_pop($this->daemonEnds);
        $this->installWarningHandler();

        try {
            $client->write();
            $this->fail('Writing into a closed daemon must raise');
        } catch (ErrorException $error) {
            $this->fail('A broken pipe must arrive as a socket exception, not ' . $error->getMessage());
        } catch (BrokenPipeException) {
        }

        $this->assertFalse($client->isConnected());
        $this->assertTrue($client->isConnectionLost());
    }

    /**
     * A peer that aborts with data still unread resets the connection, same lost state.
     *
     * Closing the peer alone is a clean end of file. The reset is the peer going away
     * without reading what this side already wrote.
     */
    public function testReadAfterPeerAbortLosesTheConnectionAsAReset(): void
    {
        $client = $this->connectedClient($daemonEnd);
        $client->send(new AgentStartDTO('unit-reset'));
        $client->write();
        $this->assertTrue($client->isConnected());
        socket_set_option($daemonEnd, SOL_SOCKET, SO_LINGER, ['l_onoff' => 1, 'l_linger' => 0]);
        socket_close($daemonEnd);
        array_pop($this->daemonEnds);
        $this->installWarningHandler();

        try {
            $client->read();
            $this->fail('A reset connection must raise');
        } catch (ErrorException $error) {
            $this->fail('A reset connection must arrive as a socket exception, not ' . $error->getMessage());
        } catch (ConnectionResetException) {
        }

        $this->assertFalse($client->isConnected());
        $this->assertTrue($client->isConnectionLost());
    }

    /**
     * Nothing to read yet is the ordinary tick, not a lost connection.
     */
    public function testWouldBlockReadStaysConnected(): void
    {
        $client = $this->connectedClient($daemonEnd);
        $this->installWarningHandler();

        $client->read();

        $this->assertTrue($client->isConnected());
        $this->assertFalse($client->isConnectionLost());
        $this->assertNull($client->getNextMessage());
        $client->close();
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

    /**
     * Installs the error handler every Hilos manager installs around its work, warnings reported.
     *
     * The same shape as {@see BaseManager::errorHandler()}: an active warning
     * becomes an ErrorException, a suppressed one is left to PHP. The suite runs with warnings
     * left out of error_reporting, which a worker does not, so they are reported again for the
     * case. Both are restored in tearDown().
     */
    private function installWarningHandler(): void
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }

            throw new ErrorException($message, 0, $severity, $file, $line);
        });
        $this->reportingBeforeHandler = error_reporting();
        error_reporting($this->reportingBeforeHandler | E_WARNING);
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
