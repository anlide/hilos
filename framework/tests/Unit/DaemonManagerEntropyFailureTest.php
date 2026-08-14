<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Router\SignalRouter;
use Hilos\Hilos;
use Hilos\Socket\Client\ClientInterface;
use Hilos\Socket\Server\AbstractServer;
use Hilos\Socket\Server\ServerInterface;
use Hilos\Socket\SocketException;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;
use Random\RandomException;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * What the node does when the secure random source refuses under a handshake
 * (HIL-568): the exception leaves the client, and the manager turns it into a
 * requested stop instead of letting it reach the top of the process, where an
 * uncaught throw would exit() without telling the workers or closing the clients.
 *
 * Both of the manager's ways into client code are driven here, because they answer
 * the refusal separately: the epoll read callback, which reads a connection in the
 * normal case and would otherwise log it as one more broken socket, and the server
 * tick, which sees the bytes that arrived between the two.
 *
 * That the node does not exit() is what every test here also proves: an exit from
 * under the exception would take the test process with it, so the assertions below
 * only run because the manager kept the loop alive.
 */
final class DaemonManagerEntropyFailureTest extends TestCase
{
    private const string REFUSAL_MESSAGE = 'source of randomness unavailable';

    /** Temporary main log file the assertions read the written line back from */
    private string $logFile = '';

    protected function setUp(): void
    {
        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-entropy-log');
        Logger::setLogFile($this->logFile);
    }

    protected function tearDown(): void
    {
        $reflection = new ReflectionClass(Logger::class);
        $reflection->getProperty('logFile')->setValue(null, null);

        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }

        Hilos::$sr = null;

        parent::tearDown();
    }

    public function testTheRefusalOnTheReadCallbackStopsTheNodeAndDropsTheConnection(): void
    {
        $manager = new DaemonManagerEntropyFailureTestManager();
        $server = new DaemonManagerEntropyFailureTestServer(null);
        $client = new DaemonManagerEntropyFailureTestClient(new RandomException(self::REFUSAL_MESSAGE));

        $manager->readClient($server, $client);

        $this->assertTrue($manager->exitRequested());
        $this->assertTrue($client->closed);
        $this->assertSame([$client], $server->removedClients);
        $this->assertStringContainsString(self::REFUSAL_MESSAGE, (string)file_get_contents($this->logFile));
    }

    /**
     * The narrow catch stands ahead of the read callback's catch-all and must not
     * take its work: a connection that fails for its own reasons is still just a
     * connection, and the node keeps serving the others.
     */
    public function testAnOrdinaryFailedReadDropsTheConnectionAndLeavesTheNodeRunning(): void
    {
        $manager = new DaemonManagerEntropyFailureTestManager();
        $server = new DaemonManagerEntropyFailureTestServer(null);
        $client = new DaemonManagerEntropyFailureTestClient(new SocketException('read failed'));

        $manager->readClient($server, $client);

        $this->assertFalse($manager->exitRequested());
        $this->assertTrue($client->closed);
        $this->assertSame([$client], $server->removedClients);
    }

    public function testTheRefusalDoesNotEscapeTheTickAndRequestsTheStop(): void
    {
        $manager = new DaemonManagerEntropyFailureTestManager();
        $manager->registerServer(new DaemonManagerEntropyFailureTestServer(self::REFUSAL_MESSAGE));

        $this->tickServers($manager);

        $this->assertTrue($manager->exitRequested());
    }

    public function testTheRefusalIsLoggedWithItsReason(): void
    {
        $manager = new DaemonManagerEntropyFailureTestManager();
        $manager->registerServer(new DaemonManagerEntropyFailureTestServer(self::REFUSAL_MESSAGE));

        $this->tickServers($manager);

        $logged = (string)file_get_contents($this->logFile);
        $this->assertStringContainsString('ERROR:', $logged);
        $this->assertStringContainsString(self::REFUSAL_MESSAGE, $logged);
    }

    /**
     * Since HIL-569 a real server contains what its clients throw, so the refusal now
     * travels a catch-all on its way here. It must pass through it: the tick guard
     * answers for a connection, and this stop is the manager's to decide.
     */
    public function testTheRefusalFromUnderTheTickGuardStillStopsTheNode(): void
    {
        $manager = new DaemonManagerEntropyFailureTestManager();
        $client = new DaemonManagerEntropyFailureTestClient(new RandomException(self::REFUSAL_MESSAGE));
        $manager->registerServer(new DaemonManagerEntropyFailureTestTickingServer($client));

        $this->tickServers($manager);

        $this->assertTrue($manager->exitRequested());
        $this->assertFalse($client->closed);
        $this->assertStringContainsString(self::REFUSAL_MESSAGE, (string)file_get_contents($this->logFile));
    }

    public function testAServerThatTicksWithoutRefusalLeavesTheNodeRunning(): void
    {
        $manager = new DaemonManagerEntropyFailureTestManager();
        $server = new DaemonManagerEntropyFailureTestServer(null);
        $manager->registerServer($server);

        $this->tickServers($manager);

        $this->assertSame(1, $server->ticks);
        $this->assertFalse($manager->exitRequested());
        $this->assertSame('', (string)file_get_contents($this->logFile));
    }

    /**
     * Runs one pass of the server tick, the loop step the refusal travels out of.
     * Reached by reflection because it is the manager's own business and widening
     * it to protected would offer an override nobody should take.
     *
     * @param DaemonManager $manager Manager under test
     */
    private function tickServers(DaemonManager $manager): void
    {
        $tickServers = new ReflectionMethod(DaemonManager::class, 'tickServers');
        $tickServers->invoke($manager);
    }
}

final class DaemonManagerEntropyFailureTestManager extends DaemonManager
{
    /**
     * @return bool True once the node has asked itself to stop
     */
    public function exitRequested(): bool
    {
        return $this->shouldExit;
    }

    /**
     * Drives the epoll read callback, the manager's usual way into client code.
     *
     * @param ServerInterface $server Server the client belongs to
     * @param ClientInterface $client Client whose socket became readable
     */
    public function readClient(ServerInterface $server, ClientInterface $client): void
    {
        $this->onClientRead($server, $client);
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new DaemonManagerEntropyFailureTestAgentManagerDaemon();
    }
}

/**
 * A server whose tick either refuses the way a handshake without entropy does, or
 * passes quietly; nothing else about it is exercised.
 */
final class DaemonManagerEntropyFailureTestServer implements ServerInterface
{
    /** @var int Number of ticks that ran to the end */
    public int $ticks = 0;

    /** @var array<int, ClientInterface> Clients the manager handed back, in order */
    public array $removedClients = [];

    /**
     * @param ?string $refusal Message of the refusal to throw, or null to tick quietly
     */
    public function __construct(private readonly ?string $refusal)
    {
    }

    /**
     * @throws RandomException When the server was built to refuse
     */
    public function onTick(): void
    {
        if ($this->refusal !== null) {
            throw new RandomException($this->refusal);
        }

        $this->ticks++;
    }

    /**
     * @return bool Always true; the test never starts a socket
     */
    public function start(): bool
    {
        return true;
    }

    public function stop(): void
    {
    }

    /**
     * @return bool Always false; the test never starts a socket
     */
    public function isRunning(): bool
    {
        return false;
    }

    /**
     * @return null No socket behind this server
     */
    public function getSocket()
    {
        return null;
    }

    /**
     * @param ClientInterface $client Client to remove
     */
    public function removeClient(ClientInterface $client): void
    {
        $this->removedClients[] = $client;
    }

    /**
     * @return ?ClientInterface Always null; the test never accepts a connection
     */
    public function acceptConnection(): ?ClientInterface
    {
        return null;
    }

    /**
     * @return string Server name for logging
     */
    public function getServerName(): string
    {
        return 'entropy-failure-test';
    }

    public function prepareShutdown(): void
    {
    }

    /**
     * @return bool Always true; the test never waits for a shutdown
     */
    public function isReadyToShutdown(): bool
    {
        return true;
    }
}

/**
 * A real server, so the refusal leaves a client through the tick guard the framework
 * puts there rather than through a stub that has none. It opens no socket: the test
 * hands it the one client it ticks.
 */
final class DaemonManagerEntropyFailureTestTickingServer extends AbstractServer
{
    /**
     * @param ClientInterface $client Client the tick reads
     */
    public function __construct(ClientInterface $client)
    {
        parent::__construct('127.0.0.1', 0);

        $this->clients[] = $client;
    }

    /**
     * @return string Server name for logging
     */
    public function getServerName(): string
    {
        return 'entropy-failure-tick-test';
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
        throw new SocketException('the entropy failure test accepts no connection');
    }
}

/**
 * A client whose read fails the way the caller asked it to; nothing else about it
 * is exercised, and it carries no socket, so the manager's cleanup skips the event
 * loop it never registered with.
 */
final class DaemonManagerEntropyFailureTestClient implements ClientInterface
{
    /** @var bool True once the manager closed this client */
    public bool $closed = false;

    /**
     * @param Throwable $failure Failure the read hands back
     */
    public function __construct(private readonly Throwable $failure)
    {
    }

    /**
     * @throws Throwable Always: the failure this client was built with
     */
    public function read(): void
    {
        throw $this->failure;
    }

    /**
     * @return null No socket behind this client
     */
    public function getSocket()
    {
        return null;
    }

    public function write(): void
    {
    }

    /**
     * @return bool Always false; the manager closes this client from its catch
     */
    public function shouldClose(): bool
    {
        return false;
    }

    public function markShouldClose(): void
    {
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function onTick(): void
    {
    }
}

final class DaemonManagerEntropyFailureTestAgentManagerDaemon extends AgentManagerDaemon
{
    /**
     * @param string $agentType Agent type that was asked for
     * @param ?string $agentIndex Agent index that was asked for
     * @return AgentDaemonInterface Never returned; the test starts no agent
     * @throws AgentDaemonCreationFailedException Always
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new AgentDaemonCreationFailedException('not used in test');
    }
}
