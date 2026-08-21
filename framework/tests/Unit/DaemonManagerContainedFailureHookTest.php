<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Daemon\ContainedFailure;
use Hilos\Core\Daemon\ContainedFailureSink;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Daemon\Master\MasterFailureUnit;
use Hilos\Core\Exception\InvalidJsonException;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Hilos;
use Hilos\Socket\Client\ClientInterface;
use Hilos\Socket\Client\WebSocketClient;
use Hilos\Socket\Server\AbstractServer;
use Hilos\Socket\Server\ServerInterface;
use Hilos\Tests\Unit\Socket\AbstractServerTickGuardTest;
use Hilos\Socket\SocketException;
use Hilos\Utils\ClientReadFailureLog;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use Throwable;

/**
 * What the master hands the project after it contains a failure (HIL-619).
 *
 * The master swallows what belongs to one connection so the node keeps serving the rest,
 * and until now the swallowing ended in the journal: a project could not count the
 * connections a broken configuration was dropping, nor say anything outwards while the
 * node was leaving after a failed loop iteration. It now takes the same card the worker
 * has had since HIL-574 and hands it to one hook, whichever guard caught the failure.
 *
 * What is checked here is the contract around that hook, not the journal: the line, its
 * level and the limiter on repeats belong to the guards and are checked where they live
 * ({@see AbstractServerTickGuardTest}, {@see DaemonManagerReadFailureLevelTest}). The one
 * line this leaf added is the hook's own, for the failure a project raises while
 * answering another.
 */
final class DaemonManagerContainedFailureHookTest extends TestCase
{
    /** Temporary main log file the assertions read the written line back from */
    private string $logFile = '';

    protected function setUp(): void
    {
        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-contained-failure-hook-log');
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

        Hilos::$sr = null;

        parent::tearDown();
    }

    public function testAConnectionThatFailedInAServerTickReachesTheHookByItsAcceptKey(): void
    {
        $manager = new DaemonManagerContainedFailureHookTestManager();
        $server = new DaemonManagerContainedFailureHookTestServer();
        $manager->registerServer($server);
        $server->seedClient(new InvalidJsonException('Payload does not decode as JSON: Syntax error'));

        $server->onTick();

        $failure = $manager->firstContained();
        $this->assertSame(MasterFailureUnit::CONNECTION, $failure->unit);
        $this->assertSame('contained-failure-hook-test acceptKey=ak-1', $failure->address);
        $this->assertInstanceOf(InvalidJsonException::class, $failure->failure);
    }

    /**
     * The same connection read by the other reader: which of the two saw the bytes first
     * is decided by how the connection happens to be registered, so a project counting
     * connection failures must not have to know which path caught one.
     */
    public function testTheSameConnectionFailingOnTheReadCallbackReachesTheHookTheSameWay(): void
    {
        $manager = new DaemonManagerContainedFailureHookTestManager();
        $server = new DaemonManagerContainedFailureHookTestServer();
        $client = new DaemonManagerContainedFailureHookTestClient(new InvalidJsonException('Payload does not decode as JSON: Syntax error'));

        $manager->readClient($server, $client);

        $failure = $manager->firstContained();
        $this->assertSame(MasterFailureUnit::CONNECTION, $failure->unit);
        $this->assertSame('contained-failure-hook-test acceptKey=ak-1', $failure->address);
    }

    public function testAConnectionThatCouldNotBeAcceptedReachesTheHookAsAnAccept(): void
    {
        $manager = new DaemonManagerContainedFailureHookTestManager();
        $server = new DaemonManagerContainedFailureHookTestServer();
        $server->refuseAccept = new SocketException('accept refused by the platform');

        $manager->acceptOn($server);

        $failure = $manager->firstContained();
        $this->assertSame(MasterFailureUnit::CONNECTION_ACCEPT, $failure->unit);
        $this->assertSame('contained-failure-hook-test', $failure->address);
        $this->assertInstanceOf(SocketException::class, $failure->failure);
    }

    /**
     * The order the node depends on: an iteration that did not finish means the node
     * leaves, and the hook is the project's last chance to say so outwards while the
     * connections are still open.
     */
    public function testAFailedLoopIterationReachesTheHookBeforeTheNodeIsToldToLeave(): void
    {
        $manager = new DaemonManagerContainedFailureHookTestManager();
        $manager->failLoopIteration = new RuntimeException('the iteration did not finish');

        // Driven by declaration rather than through run(), which would open the event
        // base first: the order under test is inside the iteration, not around it.
        new ReflectionMethod(DaemonManager::class, 'runGuardedIteration')->invoke($manager, microtime(true));

        $failure = $manager->firstContained();
        $this->assertSame(MasterFailureUnit::LOOP_ITERATION, $failure->unit);
        $this->assertSame('daemon loop', $failure->address);
        $this->assertSame([false], $manager->exitFlagsWhenCalled);
        $this->assertTrue($manager->exitRequested());
    }

    public function testAHookThatFailsIsWrittenOnItsOwnAndIsNotCalledAgainWithIt(): void
    {
        $manager = new DaemonManagerContainedFailureHookTestManager();
        $manager->hookFails = true;
        $server = new DaemonManagerContainedFailureHookTestServer();
        $manager->registerServer($server);
        $client = $server->seedClient(new InvalidJsonException('Payload does not decode as JSON: Syntax error'));

        $server->onTick();

        $this->assertSame(1, $manager->hookCalls);
        $this->assertTrue($client->closed);
        $this->assertSame([], $server->getClients());

        $logged = $this->logged();
        $this->assertStringContainsString('Failure in the contained-failure hook', $logged);
        $this->assertStringContainsString('the project hook refused', $logged);
    }

    /**
     * The limiter protects the journal, not the project: a storm of the same refusal is
     * exactly what a project counts, and counting it in step with the log would report
     * three of everything.
     */
    public function testEveryFailureOfAStreamReachesTheHookWhileTheJournalHoldsBackTheRest(): void
    {
        $manager = new DaemonManagerContainedFailureHookTestManager();
        $server = new DaemonManagerContainedFailureHookTestServer();
        $manager->registerServer($server);
        $seeded = ClientReadFailureLog::BURST_LINES + 2;
        for ($index = 0; $index < $seeded; $index++) {
            $server->seedClient(new InvalidJsonException('Payload does not decode as JSON: Syntax error'));
        }

        $server->onTick();

        $this->assertSame($seeded, $manager->hookCalls);
        $this->assertSame(
            ClientReadFailureLog::BURST_LINES,
            substr_count($this->logged(), 'Error in client tick for contained-failure-hook-test'),
        );
    }

    /**
     * @return string Everything the guards and the hook put in the journal
     */
    private function logged(): string
    {
        return (string)file_get_contents($this->logFile);
    }
}

/**
 * A manager that remembers what it was handed, and can be driven into each of the
 * guards that hand something over.
 */
final class DaemonManagerContainedFailureHookTestManager extends DaemonManager
{
    /** @var list<ContainedFailure> Cards the master handed to the project, in order */
    public array $contained = [];

    /** @var list<bool> Exit flag as it stood on entry to each call, in order */
    public array $exitFlagsWhenCalled = [];

    /** @var int Number of times the hook was called */
    public int $hookCalls = 0;

    /** @var bool Whether the project's hook refuses to answer */
    public bool $hookFails = false;

    /** @var ?Throwable Failure an iteration of the loop hands back, or null to iterate quietly */
    public ?Throwable $failLoopIteration = null;

    /**
     * @return bool True once the node has asked itself to stop
     */
    public function exitRequested(): bool
    {
        return $this->shouldExit;
    }

    /**
     * @return ContainedFailure The first card the master handed over
     */
    public function firstContained(): ContainedFailure
    {
        return $this->contained[0];
    }

    /**
     * Drives the epoll read callback, one of the two readers of a connection.
     *
     * @param ServerInterface $server Server the client belongs to
     * @param ClientInterface $client Client whose socket became readable
     */
    public function readClient(ServerInterface $server, ClientInterface $client): void
    {
        $this->onClientRead($server, $client);
    }

    /**
     * Drives the accept handler, with no socket behind it.
     *
     * @param ServerInterface $server Server whose accept fails
     */
    public function acceptOn(ServerInterface $server): void
    {
        $this->onServerAccept($server, null);
    }

    protected function onContainedFailure(ContainedFailure $failure): void
    {
        $this->hookCalls++;
        $this->contained[] = $failure;
        $this->exitFlagsWhenCalled[] = $this->shouldExit;

        if ($this->hookFails) {
            throw new RuntimeException('the project hook refused');
        }
    }

    /**
     * The first step of an iteration, and the one this test fails: everything after it
     * is skipped, because what is under test is the guard around the iteration.
     *
     * @throws Throwable The failure this manager was built with, when it was built with one
     */
    protected function processEventLoop(): void
    {
        if ($this->failLoopIteration !== null) {
            throw $this->failLoopIteration;
        }
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new DaemonManagerContainedFailureHookTestAgentManagerDaemon();
    }

    protected function setupErrorHandling(): void
    {
    }

    protected function setupSignalHandlers(): void
    {
    }

    protected function sleepWithPreciseTiming(float $loopStartTime, int $targetLoopTimeMicroseconds = 10000): void
    {
    }
}

/**
 * A server that ticks the clients the test hands it, and refuses an accept when the
 * test asked it to; it opens no socket.
 */
final class DaemonManagerContainedFailureHookTestServer extends AbstractServer
{
    /** @var ?Throwable Failure the accept hands back, or null to accept nothing quietly */
    public ?Throwable $refuseAccept = null;

    public function __construct()
    {
        parent::__construct('127.0.0.1', 0);
    }

    /**
     * Puts a client in front of the tick as an accepted connection would.
     *
     * @param Throwable $failure Failure the client's read hands back
     * @return DaemonManagerContainedFailureHookTestClient Client the tick will see
     */
    public function seedClient(Throwable $failure): DaemonManagerContainedFailureHookTestClient
    {
        $client = new DaemonManagerContainedFailureHookTestClient($failure);
        $this->clients[] = $client;

        return $client;
    }

    /**
     * @return ?ClientInterface Never a client; the test either refuses or accepts nothing
     * @throws Throwable The refusal this server was built with, when it was built with one
     */
    public function acceptConnection(): ?ClientInterface
    {
        if ($this->refuseAccept !== null) {
            throw $this->refuseAccept;
        }

        return null;
    }

    /**
     * @return string Server name the card and the journal line name
     */
    public function getServerName(): string
    {
        return 'contained-failure-hook-test';
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
        throw new SocketException('the contained-failure hook test accepts no connection');
    }
}

/**
 * A WebSocket connection past its handshake whose read fails the way the test asked.
 *
 * It is a {@see WebSocketClient} and not a bare client because the accept key is the
 * half of the address that only this kind of connection has, and the constructor of the
 * real one is skipped: it wants a live socket and the environment, and nothing below
 * reads either.
 */
final class DaemonManagerContainedFailureHookTestClient extends WebSocketClient
{
    /** @var bool True once the guard closed this client */
    public bool $closed = false;

    /**
     * @param Throwable $failure Failure the read hands back
     */
    public function __construct(private readonly Throwable $failure)
    {
        $this->acceptKey = 'ak-1';
    }

    /**
     * @throws Throwable The failure this client was built with
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
     * @return bool Always false; the guard closes this client itself
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

    /**
     * @param array<string, string> $headers HTTP headers from handshake request
     * @param string $acceptKey Daemon-minted connection identifier
     * @param array<string, string> $cookies Parsed cookies from Cookie header
     * @param ?string $clientIp Client IP, or null when the peer name is unavailable
     * @param RequestQueryParams $queryParams Query parameters from request URL
     */
    protected function onHandshake(
        array $headers,
        string $acceptKey,
        array $cookies,
        ?string $clientIp,
        RequestQueryParams $queryParams,
    ): void {
    }
}

/**
 * An agent manager daemon the test never asks for an agent.
 */
final class DaemonManagerContainedFailureHookTestAgentManagerDaemon extends AgentManagerDaemon
{
    /**
     * @param string $agentType Agent type to create
     * @param ?string $agentIndex Agent index
     * @return AgentDaemonInterface Never returned; the test starts no agent
     * @throws AgentDaemonCreationFailedException Always
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new AgentDaemonCreationFailedException('the contained-failure hook test starts no agent');
    }
}
