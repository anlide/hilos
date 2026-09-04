<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Socket;

use Hilos\Core\Daemon\ClientSocketDetacher;
use Hilos\Core\Exception\InvalidJsonException;
use Hilos\Socket\Client\ClientInterface;
use Hilos\Socket\Server\AbstractServer;
use Hilos\Socket\SocketException;
use Hilos\Tests\Unit\DaemonManagerClientExitTest;
use Hilos\Utils\ClientReadFailureLog;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * The one way out a client takes when it leaves a server (HIL-620).
 *
 * A client used to leave by whatever the local path assembled: three places in the
 * master took its socket off the watch before closing it and three in the Socket layer
 * did not, because the layer is not given the loop at all. What the three quiet ones
 * left behind was a live registration pointing at a closed descriptor - and, through the
 * read callback, at the dead client - growing for as long as the master lived.
 *
 * Now every exit goes through one door, and the order in it is what these tests read:
 * off the watch first, closed second, forgotten third, whether the client asked to go,
 * failed on the tick, or was still there when the server stopped. The door is also
 * idempotent and survives a close that throws, because both happen on paths that are
 * already handling a failure.
 *
 * That the registration itself is gone afterwards is measured against a real event loop
 * in {@see DaemonManagerClientExitTest}; here the seam is a recorder, so the order is
 * visible without a socket.
 */
final class AbstractServerClientExitTest extends TestCase
{
    /** Temporary main log file the contained-failure path writes its line to */
    private string $logFile = '';

    protected function setUp(): void
    {
        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-client-exit-log');
        Logger::setLogFile($this->logFile);
        ClientReadFailureLog::reset();
    }

    protected function tearDown(): void
    {
        Logger::resetLogFile();
        ClientReadFailureLog::reset();

        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }

        parent::tearDown();
    }

    public function testAClientThatAsksToGoComesOffTheWatchBeforeItIsClosed(): void
    {
        $journal = new AbstractServerClientExitTestJournal();
        $server = new AbstractServerClientExitTestServer();
        $server->setClientSocketDetacher(new AbstractServerClientExitTestDetacher($journal));
        $server->seedClient(new AbstractServerClientExitTestClient($journal, null, true));

        $server->onTick();

        $this->assertSame(['detach', 'close'], $journal->steps);
        $this->assertSame([], $server->getClients());
    }

    public function testAClientThatFailedOnTheTickComesOffTheWatchBeforeItIsClosed(): void
    {
        $journal = new AbstractServerClientExitTestJournal();
        $server = new AbstractServerClientExitTestServer();
        $server->setClientSocketDetacher(new AbstractServerClientExitTestDetacher($journal));
        $server->seedClient(new AbstractServerClientExitTestClient(
            $journal,
            new InvalidJsonException('Payload does not decode as JSON: Syntax error'),
            false,
        ));

        $server->onTick();

        $this->assertSame(['detach', 'close'], $journal->steps);
        $this->assertSame([], $server->getClients());
    }

    /**
     * The path the ticket did not count: the master does not leak here today, because
     * the loop is freed right after the servers stop, but the rule is the rule and the
     * next caller of stop() would inherit the hole the other three had.
     */
    public function testAClientStillHeldWhenTheServerStopsComesOffTheWatchBeforeItIsClosed(): void
    {
        $journal = new AbstractServerClientExitTestJournal();
        $server = new AbstractServerClientExitTestServer();
        $server->setClientSocketDetacher(new AbstractServerClientExitTestDetacher($journal));
        $server->seedClient(new AbstractServerClientExitTestClient($journal, null, false));

        $server->stop();

        $this->assertSame(['detach', 'close'], $journal->steps);
        $this->assertSame([], $server->getClients());
    }

    /**
     * What the deferred removal used to get wrong: a close that threw skipped the line
     * that struck the client off, and the server went on ticking a dead connection.
     */
    public function testAClientWhoseCloseFailedIsStillStruckOff(): void
    {
        $journal = new AbstractServerClientExitTestJournal();
        $server = new AbstractServerClientExitTestServer();
        $server->setClientSocketDetacher(new AbstractServerClientExitTestDetacher($journal));
        $client = $server->seedClient(new AbstractServerClientExitTestClient($journal, null, false, true));

        try {
            $server->dropClient($client);
            $this->fail('A close that fails must reach the caller.');
        } catch (SocketException $exception) {
            $this->assertSame('this connection refuses to close', $exception->getMessage());
        }

        $this->assertSame([], $server->getClients());
    }

    /**
     * Entering twice is the ordinary case, not a defensive one: the master reaches the
     * door for a client that asked to go, and the catch-all above it reaches the same
     * door again when that first attempt threw.
     */
    public function testEnteringTheDoorTwiceForTheSameClientIsHarmless(): void
    {
        $journal = new AbstractServerClientExitTestJournal();
        $server = new AbstractServerClientExitTestServer();
        $server->setClientSocketDetacher(new AbstractServerClientExitTestDetacher($journal));
        $client = $server->seedClient(new AbstractServerClientExitTestClient($journal, null, false));

        $server->dropClient($client);
        $server->dropClient($client);

        $this->assertSame(['detach', 'close', 'detach', 'close'], $journal->steps);
        $this->assertSame([], $server->getClients());
    }

    /**
     * A server nobody handed the seam to drops its client the way it always did. It
     * leaks nothing by it either: registration is the only route a server's sockets take
     * to the watch, and a server that was never registered put none of them there.
     */
    public function testAServerWithoutTheSeamDropsItsClientExactlyAsBefore(): void
    {
        $journal = new AbstractServerClientExitTestJournal();
        $server = new AbstractServerClientExitTestServer();
        $client = $server->seedClient(new AbstractServerClientExitTestClient($journal, null, true));

        $server->onTick();

        $this->assertSame(['close'], $journal->steps);
        $this->assertTrue($client->closed);
        $this->assertSame([], $server->getClients());
    }
}

/**
 * The order the door went through, written down by whoever was called.
 */
final class AbstractServerClientExitTestJournal
{
    /** @var list<string> Steps of the exit, in the order they happened */
    public array $steps = [];

    /**
     * @param string $step Name of the step that just ran
     */
    public function record(string $step): void
    {
        $this->steps[] = $step;
    }
}

/**
 * A server that holds the clients the test hands it and opens no socket of its own.
 */
final class AbstractServerClientExitTestServer extends AbstractServer
{
    public function __construct()
    {
        parent::__construct('127.0.0.1', 0);
    }

    /**
     * Puts a client in front of the server as an accepted connection would.
     *
     * @param AbstractServerClientExitTestClient $client Client the server will hold
     * @return AbstractServerClientExitTestClient The same client, for the assertions
     */
    public function seedClient(AbstractServerClientExitTestClient $client): AbstractServerClientExitTestClient
    {
        $this->clients[] = $client;

        return $client;
    }

    /**
     * @return string Server name the journal line names
     */
    public function getServerName(): string
    {
        return 'client-exit-test';
    }

    protected function onStart(): void
    {
    }

    /**
     * @param resource $socket Client socket
     * @return ClientInterface Never returned; the test accepts no connection
     * @throws SocketException Always
     */
    protected function onCreateClient($socket): ClientInterface
    {
        throw new SocketException('the client exit test accepts no connection');
    }
}

/**
 * A client that writes down when it was closed, and fails on the tick or on the close
 * when the test asked it to.
 */
final class AbstractServerClientExitTestClient implements ClientInterface
{
    /** @var bool True once the client was closed */
    public bool $closed = false;

    /**
     * @param AbstractServerClientExitTestJournal $journal Where the close is written down
     * @param ?Throwable $readFailure Failure the read hands back, or null to read quietly
     * @param bool $shouldClose Whether the client asks the tick to let it go
     * @param bool $refuseClose Whether the close fails instead of succeeding
     */
    public function __construct(
        private readonly AbstractServerClientExitTestJournal $journal,
        private readonly ?Throwable $readFailure,
        private readonly bool $shouldClose,
        private readonly bool $refuseClose = false,
    ) {
    }

    /**
     * @throws Throwable The failure this client was built with, when it was built with one
     */
    public function read(): void
    {
        if ($this->readFailure !== null) {
            throw $this->readFailure;
        }
    }

    /**
     * @return null No socket behind this client; the order is what the test reads
     */
    public function getSocket()
    {
        return null;
    }

    public function write(): void
    {
    }

    /**
     * @return bool Whether the client asks the tick to let it go
     */
    public function shouldClose(): bool
    {
        return $this->shouldClose;
    }

    public function markShouldClose(): void
    {
    }

    /**
     * @throws SocketException When the test built this client to refuse the close
     */
    public function close(): void
    {
        $this->journal->record('close');

        if ($this->refuseClose) {
            throw new SocketException('this connection refuses to close');
        }

        $this->closed = true;
    }

    public function onTick(): void
    {
    }
}

/**
 * The master seam, standing in for the event loop: it writes down that it was told, so
 * the test can read the order without a socket to watch.
 */
final class AbstractServerClientExitTestDetacher implements ClientSocketDetacher
{
    /**
     * @param AbstractServerClientExitTestJournal $journal Where the detach is written down
     */
    public function __construct(private readonly AbstractServerClientExitTestJournal $journal)
    {
    }

    /**
     * @param ClientInterface $client Client whose socket is about to be closed
     */
    public function detachClientSocket(ClientInterface $client): void
    {
        $this->journal->record('detach');
    }
}
