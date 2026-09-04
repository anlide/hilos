<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\Router\SignalRouter;
use Hilos\Hilos;
use Hilos\Socket\Client\ClientInterface;
use Hilos\Socket\Server\AbstractServer;
use Hilos\Socket\SocketException;
use PHPUnit\Framework\TestCase;

/**
 * The measurement the ticket asked for: what the watch holds after a client leaves (HIL-620).
 *
 * The sibling test reads the ORDER of the exit against a recorder. This one reads its
 * EFFECT against the real thing - a real {@see EventLoop}, a real socket pair, a real
 * registration - because the defect was never visible in the order alone. A client that
 * left through a server tick used to be closed and struck off exactly as it should be,
 * and the registration pointing at its now-closed descriptor stayed behind. Nothing
 * failed, nothing was logged; the count simply never came down again on a master that
 * ran for weeks.
 *
 * So the assertion is a number: one socket on the watch before the tick, none after.
 * Before this leaf that number stayed at one, and that is the monotonic growth the
 * ticket describes.
 */
final class DaemonManagerClientExitTest extends TestCase
{
    /** @var array<int, resource|object> Socket pair the test opened; the test never closes it itself */
    private array $pair = [];

    /** @var ?EventLoop Loop the test registered with, freed before its sockets go */
    private ?EventLoop $loop = null;

    protected function tearDown(): void
    {
        $this->loop?->cleanup();
        $this->loop = null;

        foreach ($this->pair as $socket) {
            socket_close($socket);
        }
        $this->pair = [];

        Hilos::$sr = null;

        parent::tearDown();
    }

    public function testAClientLeavingThroughTheServerTickComesOffTheWatch(): void
    {
        $loop = new EventLoop();
        $manager = new DaemonManagerClientExitTestManager();
        $manager->seedEventLoop($loop);

        $server = new DaemonManagerClientExitTestServer();
        $manager->registerServer($server);
        $server->seedClient(new DaemonManagerClientExitTestClient($this->watchedSocket($loop)));

        $this->assertSame(1, $loop->registeredCount());

        $server->onTick();

        $this->assertSame(0, $loop->registeredCount());
        $this->assertSame([], $server->getClients());
    }

    /**
     * The defect itself, still reproducible on demand: a server that was never handed the
     * seam does everything else right - closes the connection, strikes it off - and leaves
     * the registration standing. That is exactly what the whole Socket layer did before
     * this leaf, and it is why the count is the assertion and the close is not.
     */
    public function testAServerWithNoSeamConcludesTheExitAndLeavesTheWatchStanding(): void
    {
        $loop = new EventLoop();
        $server = new DaemonManagerClientExitTestServer();
        $server->seedClient(new DaemonManagerClientExitTestClient($this->watchedSocket($loop)));

        $server->onTick();

        $this->assertSame(1, $loop->registeredCount());
        $this->assertSame([], $server->getClients());
    }

    /**
     * Opens a socket pair and puts one end of it on the watch, the way the master does
     * for an accepted connection.
     *
     * @param EventLoop $loop Loop the socket is registered with
     * @return resource|object The watched end of the pair
     */
    private function watchedSocket(EventLoop $loop)
    {
        $pair = [];
        if (!socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair)) {
            $this->fail('the test could not open a socket pair to watch');
        }
        $this->pair = $pair;
        $this->loop = $loop;

        $loop->registerRead($pair[0], fn() => null);

        return $pair[0];
    }
}

/**
 * A manager that lets the test put a loop under it: the real one builds its loop inside
 * run(), which wants a bound socket and a live environment the assertions never touch.
 */
final class DaemonManagerClientExitTestManager extends DaemonManager
{
    /**
     * @param EventLoop $loop Loop the manager detaches sockets from
     */
    public function seedEventLoop(EventLoop $loop): void
    {
        $this->eventLoop = $loop;
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new DaemonManagerClientExitTestAgentManagerDaemon();
    }

    protected function setupErrorHandling(): void
    {
    }

    protected function setupSignalHandlers(): void
    {
    }
}

/**
 * A server that holds the client the test hands it and opens no socket of its own.
 */
final class DaemonManagerClientExitTestServer extends AbstractServer
{
    public function __construct()
    {
        parent::__construct('127.0.0.1', 0);
    }

    /**
     * Puts a client in front of the server as an accepted connection would.
     *
     * @param DaemonManagerClientExitTestClient $client Client the server will hold
     */
    public function seedClient(DaemonManagerClientExitTestClient $client): void
    {
        $this->clients[] = $client;
    }

    /**
     * @return string Server name the journal line would name
     */
    public function getServerName(): string
    {
        return 'client-exit-measurement-test';
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
        throw new SocketException('the client exit measurement test accepts no connection');
    }
}

/**
 * A connection that holds one end of the test's socket pair and asks to go on the first
 * tick it sees.
 */
final class DaemonManagerClientExitTestClient implements ClientInterface
{
    /**
     * @param resource|object $socket The watched end of the test's socket pair
     */
    public function __construct(private $socket)
    {
    }

    public function read(): void
    {
    }

    /**
     * @return resource|object|null The watched socket, until this client is closed
     */
    public function getSocket()
    {
        return $this->socket;
    }

    public function write(): void
    {
    }

    /**
     * @return bool Always true; the client asks the tick to let it go
     */
    public function shouldClose(): bool
    {
        return true;
    }

    public function markShouldClose(): void
    {
    }

    public function close(): void
    {
        $this->socket = null;
    }

    public function onTick(): void
    {
    }
}

/**
 * An agent manager the constructor of the real one needs and this test never drives.
 */
final class DaemonManagerClientExitTestAgentManagerDaemon extends AgentManagerDaemon
{
    /**
     * @param string $agentType Agent type to create
     * @param ?string $agentIndex Agent index
     * @return AgentDaemonInterface Never returned; the test starts no agent
     * @throws AgentDaemonCreationFailedException Always
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new AgentDaemonCreationFailedException('the client exit test starts no agent');
    }
}
