<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Exception\InvalidJsonException;
use Hilos\Core\Router\SignalRouter;
use Hilos\Hilos;
use Hilos\Socket\Client\ClientInterface;
use Hilos\Socket\Server\ServerInterface;
use Hilos\Socket\SocketException;
use Hilos\Utils\ClientReadFailureLog;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;
use Random\RandomException;
use RuntimeException;
use Throwable;

/**
 * At which level the event loop's reader writes a client that failed (HIL-601).
 *
 * A connection is read from two places in the master, and which of them gets a given
 * failure is decided by how the connection happens to be registered: the same bad line
 * used to reach the journal as a warning from the server tick and as an error from
 * here, because this path wrote every failure through the manager's error logger. It
 * now asks the failure what it is, like the other reader always did.
 *
 * The refusal of the secure random source is checked again here, from this angle: it
 * is caught ahead of the shared writer and must stay the node's business (HIL-568),
 * which is exactly what a rewrite of the catch below it could quietly undo.
 */
final class DaemonManagerReadFailureLevelTest extends TestCase
{
    /** Temporary main log file the assertions read the written line back from */
    private string $logFile = '';

    protected function setUp(): void
    {
        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-read-failure-level-log');
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

    public function testInputThatCouldNotBeParsedIsWrittenAsTheRoutineEventItIs(): void
    {
        $manager = new DaemonManagerReadFailureLevelTestManager();
        $server = new DaemonManagerReadFailureLevelTestServer();
        $client = new DaemonManagerReadFailureLevelTestClient(
            new InvalidJsonException('Payload does not decode as JSON: Syntax error'),
        );

        $manager->readClient($server, $client);

        $logged = $this->logged();
        $this->assertStringContainsString('WARNING:', $logged);
        $this->assertStringNotContainsString('ERROR:', $logged);
        $this->assertStringContainsString('Error in client read handler for read-failure-level-test', $logged);
        $this->assertTrue($client->closed);
        $this->assertSame([$client], $server->removedClients);
    }

    public function testABrokenTransportIsWrittenAsTheRoutineEventItIs(): void
    {
        $manager = new DaemonManagerReadFailureLevelTestManager();
        $client = new DaemonManagerReadFailureLevelTestClient(new SocketException('Connection reset by peer'));

        $manager->readClient(new DaemonManagerReadFailureLevelTestServer(), $client);

        $logged = $this->logged();
        $this->assertStringContainsString('WARNING:', $logged);
        $this->assertStringNotContainsString('ERROR:', $logged);
    }

    public function testAFailureCarryingNoMarkerIsWrittenAsAnErrorAndStillDropsTheClient(): void
    {
        $manager = new DaemonManagerReadFailureLevelTestManager();
        $client = new DaemonManagerReadFailureLevelTestClient(new RuntimeException('worker table is out of shape'));

        $manager->readClient(new DaemonManagerReadFailureLevelTestServer(), $client);

        $logged = $this->logged();
        $this->assertStringContainsString('ERROR:', $logged);
        $this->assertStringContainsString('worker table is out of shape', $logged);
        $this->assertTrue($client->closed);
    }

    public function testTheRefusalOfTheSecureRandomSourceStillStopsTheNode(): void
    {
        $manager = new DaemonManagerReadFailureLevelTestManager();
        $client = new DaemonManagerReadFailureLevelTestClient(new RandomException('source of randomness unavailable'));

        $manager->readClient(new DaemonManagerReadFailureLevelTestServer(), $client);

        $this->assertTrue($manager->exitRequested());
        $this->assertTrue($client->closed);
        $this->assertStringContainsString('source of randomness unavailable', $this->logged());
    }

    /**
     * @return string Everything the read handler put in the journal
     */
    private function logged(): string
    {
        return (string)file_get_contents($this->logFile);
    }
}

final class DaemonManagerReadFailureLevelTestManager extends DaemonManager
{
    /**
     * @return bool True once the node has asked itself to stop
     */
    public function exitRequested(): bool
    {
        return $this->shouldExit;
    }

    /**
     * Drives the epoll read callback, the path whose level this test is about.
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
        return new DaemonManagerReadFailureLevelTestAgentManagerDaemon();
    }
}

/**
 * A server that only names itself and remembers the clients it was handed back;
 * the test never opens a socket.
 */
final class DaemonManagerReadFailureLevelTestServer implements ServerInterface
{
    /** @var array<int, ClientInterface> Clients the manager handed back, in order */
    public array $removedClients = [];

    public function onTick(): void
    {
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
     * @return string Server name the journal line names
     */
    public function getServerName(): string
    {
        return 'read-failure-level-test';
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
 * A client whose read fails the way the test asked it to; it carries no socket, so
 * the manager's cleanup skips the event loop it never registered with.
 */
final class DaemonManagerReadFailureLevelTestClient implements ClientInterface
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

final class DaemonManagerReadFailureLevelTestAgentManagerDaemon extends AgentManagerDaemon
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
