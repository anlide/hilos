<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\ExitCode;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Daemon\DaemonDeparture;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Router\SignalRouter;
use Hilos\Hilos;
use Hilos\Socket\Client\ClientInterface;
use Hilos\Socket\Server\AbstractServer;
use Hilos\Socket\SocketException;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;
use Random\RandomException;
use ReflectionMethod;
use RuntimeException;
use Throwable;

/**
 * Whether the node says it left healthy (HIL-683).
 *
 * Until now every departure ended the process with a zero: a failed loop iteration is
 * turned into a requested stop (HIL-569), and so is a refusal of the secure random source
 * (HIL-568), so both leave by the path SIGTERM takes and both used to report success. A
 * supervisor reading the code - systemd, CI, a future `restart: on-failure` - could not
 * tell a node that crashed from one that was asked to stop.
 *
 * The reason is now kept on the manager and the entrypoint turns it into a number, so
 * this file checks the two halves separately: the mapping a reason carries, and which
 * paths leave a mark on the way out. What the mark means is the decision this defends -
 * {@see DaemonDeparture::Failed} says a failure happened on the node's way, not that the
 * node left unasked, so the order of a failure and a request to stop does not matter.
 */
final class DaemonManagerDepartureTest extends TestCase
{
    /** Temporary main log file the guards write to, so the run says nothing on stdout */
    private string $logFile = '';

    protected function setUp(): void
    {
        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-departure-log');
        Logger::setLogFile($this->logFile);
    }

    protected function tearDown(): void
    {
        Logger::resetLogFile();

        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }

        Hilos::$sr = null;

        parent::tearDown();
    }

    /**
     * The half of the contract the entrypoint executes: it asks the reason for a number
     * and exits with it, so the mapping is checked here rather than inside the method
     * that ends the process, where nothing could reach it.
     */
    public function testEachDepartureNamesTheProcessCodeItIsReportedWith(): void
    {
        $this->assertSame(ExitCode::SUCCESS, DaemonDeparture::Stopped->exitCode());
        $this->assertSame(ExitCode::ERROR, DaemonDeparture::Failed->exitCode());
    }

    /**
     * The default, and the reason SIGTERM and SIGHUP write nothing: they live in
     * BaseManager, shared with the worker, the docker supervisor and the CLI monitor,
     * and marking the ordinary case would mean editing that shared base for a value the
     * field already holds.
     */
    public function testANodeAskedToStopLeavesAsAnOrdinaryDeparture(): void
    {
        $manager = new DaemonManagerDepartureTestManager();
        $this->assertSame(DaemonDeparture::Stopped, $manager->departure());

        $manager->handleShutdown();

        $this->assertTrue($manager->exitRequested());
        $this->assertSame(DaemonDeparture::Stopped, $manager->departure());
    }

    public function testAFailedLoopIterationMakesTheDepartureAForcedOne(): void
    {
        $manager = new DaemonManagerDepartureTestManager();
        $manager->failLoopIteration = new RuntimeException('the iteration did not finish');

        $this->runIteration($manager);

        $this->assertTrue($manager->exitRequested());
        $this->assertSame(DaemonDeparture::Failed, $manager->departure());
    }

    /**
     * The other path the owner named: a node whose secure random source refused cannot
     * mint the secrets a handshake hands out, and it leaves for that reason - which is a
     * failure on its way out however calmly it is requested.
     */
    public function testTheEntropyStopMakesTheDepartureAForcedOne(): void
    {
        $manager = new DaemonManagerDepartureTestManager();
        $manager->registerServer(new DaemonManagerDepartureTestRefusingServer());

        $this->tickServers($manager);

        $this->assertTrue($manager->exitRequested());
        $this->assertSame(DaemonDeparture::Failed, $manager->departure());
    }

    /**
     * The decision this file exists for. A request to leave that arrives after a failure
     * does not erase the diagnosis, because it writes nothing at all - and that is wanted:
     * the exit code answers "did the node leave healthy", and a node whose loop iteration
     * fell is unhealthy whenever the request happened to arrive.
     */
    public function testARequestToStopAfterAFailureDoesNotEraseTheDiagnosis(): void
    {
        $manager = new DaemonManagerDepartureTestManager();
        $manager->failLoopIteration = new RuntimeException('the iteration did not finish');

        $this->runIteration($manager);
        $manager->handleShutdown();

        $this->assertSame(DaemonDeparture::Failed, $manager->departure());
    }

    /**
     * The three PHP handlers, marked so the list of forced paths is complete in one place.
     * Two of them never reach the return from run() in production - PHP ends the process
     * itself after an uncaught exception or on its way out - and they are checked here
     * because a reader of the list should not have to work out which.
     */
    public function testEachPhpHandlerHookMakesTheDepartureAForcedOne(): void
    {
        $onError = new DaemonManagerDepartureTestManager();
        $onError->raiseError();
        $this->assertSame(DaemonDeparture::Failed, $onError->departure());

        $onException = new DaemonManagerDepartureTestManager();
        $onException->raiseException();
        $this->assertSame(DaemonDeparture::Failed, $onException->departure());

        $onShutdown = new DaemonManagerDepartureTestManager();
        $onShutdown->raiseShutdown();
        $this->assertSame(DaemonDeparture::Failed, $onShutdown->departure());
    }

    /**
     * Runs one guarded iteration by declaration rather than through run(), which would
     * open the event base first: what is under test is inside the iteration, not the loop
     * around it.
     *
     * @param DaemonManager $manager Manager under test
     */
    private function runIteration(DaemonManager $manager): void
    {
        new ReflectionMethod(DaemonManager::class, 'runGuardedIteration')->invoke($manager, microtime(true));
    }

    /**
     * Runs one pass of the server tick, the loop step the entropy refusal travels out of.
     *
     * @param DaemonManager $manager Manager under test
     */
    private function tickServers(DaemonManager $manager): void
    {
        new ReflectionMethod(DaemonManager::class, 'tickServers')->invoke($manager);
    }
}

/**
 * A manager that reports the reason it would leave with, and can be driven into each of
 * the paths that mark one.
 *
 * The reason is read through a public getter rather than by reflection on the field: the
 * field is protected for exactly this, so a test subclass can hand it out.
 */
final class DaemonManagerDepartureTestManager extends DaemonManager
{
    /** @var ?Throwable Failure an iteration of the loop hands back, or null to iterate quietly */
    public ?Throwable $failLoopIteration = null;

    /**
     * @return DaemonDeparture Reason this node would report to the entrypoint
     */
    public function departure(): DaemonDeparture
    {
        return $this->departure;
    }

    /**
     * @return bool True once the node has asked itself to stop
     */
    public function exitRequested(): bool
    {
        return $this->shouldExit;
    }

    /**
     * Drives the handler PHP calls for an error the process cannot continue past.
     */
    public function raiseError(): void
    {
        $this->onError();
    }

    /**
     * Drives the handler PHP calls for an uncaught exception.
     */
    public function raiseException(): void
    {
        $this->onException();
    }

    /**
     * Drives the handler PHP calls while the process is shutting down.
     */
    public function raiseShutdown(): void
    {
        $this->onShutdown();
    }

    /**
     * The first step of an iteration, and the one this test fails: everything after it is
     * skipped, because what is under test is the mark the guard around the iteration leaves.
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
        return new DaemonManagerDepartureTestAgentManagerDaemon();
    }
}

/**
 * A server whose tick refuses the way a handshake without entropy does; it opens no
 * socket and holds no client.
 */
final class DaemonManagerDepartureTestRefusingServer extends AbstractServer
{
    public function __construct()
    {
        parent::__construct('127.0.0.1', 0);
    }

    /**
     * @throws RandomException Always: the refusal the node stops over
     */
    public function onTick(): void
    {
        throw new RandomException('source of randomness unavailable');
    }

    /**
     * @return string Server name the journal line names
     */
    public function getServerName(): string
    {
        return 'departure-test';
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
        throw new SocketException('the departure test accepts no connection');
    }
}

/**
 * An agent manager daemon the test never asks for an agent.
 */
final class DaemonManagerDepartureTestAgentManagerDaemon extends AgentManagerDaemon
{
    /**
     * @param string $agentType Agent type to create
     * @param ?string $agentIndex Agent index
     * @return AgentDaemonInterface Never returned; the test starts no agent
     * @throws AgentDaemonCreationFailedException Always
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new AgentDaemonCreationFailedException('the departure test starts no agent');
    }
}
