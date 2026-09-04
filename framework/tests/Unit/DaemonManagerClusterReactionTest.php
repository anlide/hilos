<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\ClusterContext;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Daemon\ClientSocketDetacher;
use Hilos\Core\Daemon\ContainedFailureSink;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Router\SignalRouter;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Socket\Client\ClientInterface;
use Hilos\Socket\Server\ServerInterface;
use Hilos\Socket\SocketException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Throwable;

/**
 * Unit tests for the daemon's quorum-loss and graceful-leave reactions (HIL-341).
 */
final class DaemonManagerClusterReactionTest extends TestCase
{
    private ?EnvAccessor $previousEnv = null;

    private ?ClusterContext $previousCluster = null;

    protected function setUp(): void
    {
        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        $this->previousCluster = Hilos::$cluster;
    }

    protected function tearDown(): void
    {
        Hilos::$sr = null;
        Hilos::$env = $this->previousEnv;
        Hilos::$cluster = $this->previousCluster;
        putenv('CLUSTER_ENABLED');

        parent::tearDown();
    }

    public function testQuorumLossFiresTheBroadWorkStop(): void
    {
        $manager = new DaemonManagerClusterReactionTestManager();

        $manager->onQuorumLost();

        $this->assertSame(1, $manager->workStopCount, 'A minority-partition node halts business work at once');
    }

    public function testLostLeadershipReArmsTheSingletonEnsureOnce(): void
    {
        $manager = new DaemonManagerClusterReactionTestManager();

        $flag = new ReflectionProperty(DaemonManager::class, 'singletonsStarted');
        $flag->setValue($manager, true);

        $manager->onLostLeadership(3);

        $this->assertFalse($flag->getValue($manager), 'A later promotion must re-run the singleton start');
        $this->assertSame(0, $manager->workStopCount, 'Leadership loss alone is the narrow singleton teardown, not a work-stop');
    }

    public function testGracefulLeaveFiresTheWorkStopWhenClustered(): void
    {
        Hilos::$env = new EnvAccessor();
        putenv('CLUSTER_ENABLED=true');
        Hilos::$cluster = new ClusterContext();

        $manager = new DaemonManagerClusterReactionTestManager();

        $initiateShutdown = new ReflectionMethod(DaemonManager::class, 'initiateShutdown');
        $initiateShutdown->invoke($manager);

        $this->assertSame(1, $manager->workStopCount, 'A planned graceful-leave lets the project persist and stop its work');
    }

    public function testGracefulLeaveIsSilentWhenClusterModeIsOff(): void
    {
        Hilos::$env = new EnvAccessor();
        Hilos::$cluster = new ClusterContext();

        $manager = new DaemonManagerClusterReactionTestManager();

        $initiateShutdown = new ReflectionMethod(DaemonManager::class, 'initiateShutdown');
        $initiateShutdown->invoke($manager);

        $this->assertSame(0, $manager->workStopCount, 'A standalone daemon shutdown is not a cluster graceful-leave');
    }

    /**
     * The point of the step is that every server gets to close its clients (HIL-569),
     * so a server refusing on its way out must not take that chance from the ones
     * standing behind it in the list.
     */
    public function testAServerRefusingToPrepareLeavesTheOthersTheirShutdown(): void
    {
        Hilos::$env = new EnvAccessor();
        Hilos::$cluster = new ClusterContext();

        $manager = new DaemonManagerClusterReactionTestManager();
        $refusing = new DaemonManagerClusterReactionTestServer('refusing', new RuntimeException('port already gone'));
        $following = new DaemonManagerClusterReactionTestServer('following', null);
        $manager->registerServer($refusing);
        $manager->registerServer($following);

        $initiateShutdown = new ReflectionMethod(DaemonManager::class, 'initiateShutdown');
        $initiateShutdown->invoke($manager);

        $this->assertTrue($following->prepared, 'The server behind the refusing one is still told to prepare');
    }

    /**
     * The work-stop hook is a project's own code and is invited to persist, so its
     * refusal must not cost the node its departure: without the servers' prepare step
     * there is no NodeLeaving frame and no closed client, only the shutdown timeout.
     */
    public function testARefusingWorkStopStillLeavesTheServersTheirShutdown(): void
    {
        Hilos::$env = new EnvAccessor();
        putenv('CLUSTER_ENABLED=true');
        Hilos::$cluster = new ClusterContext();

        $manager = new DaemonManagerClusterReactionTestManager();
        $manager->workStopRefuses = true;
        $server = new DaemonManagerClusterReactionTestServer('following', null);
        $manager->registerServer($server);

        $initiateShutdown = new ReflectionMethod(DaemonManager::class, 'initiateShutdown');
        $initiateShutdown->invoke($manager);

        $this->assertSame(1, $manager->workStopCount, 'The hook was reached');
        $this->assertTrue($server->prepared, 'A refusing hook does not skip the servers behind it');
    }
}

final class DaemonManagerClusterReactionTestManager extends DaemonManager
{
    /** @var int Number of times onClusterWorkStop() fired */
    public int $workStopCount = 0;

    /** @var bool Whether the work-stop hook refuses, the way a project's persistence can */
    public bool $workStopRefuses = false;

    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new DaemonManagerClusterReactionTestAgentManagerDaemon();
    }

    /**
     * @throws RuntimeException When the test asked the hook to refuse
     */
    public function onClusterWorkStop(): void
    {
        $this->workStopCount++;

        if ($this->workStopRefuses) {
            throw new RuntimeException('the project could not persist its work');
        }
    }
}

/**
 * A server that answers the shutdown step the way the test asked it to and remembers
 * being asked; nothing else about it is exercised.
 */
final class DaemonManagerClusterReactionTestServer implements ServerInterface
{
    /** @var bool True once the manager told this server to prepare for shutdown */
    public bool $prepared = false;

    /**
     * @param string $name Server name the failure line names
     * @param ?Throwable $refusal Failure prepareShutdown() hands back, or null to prepare quietly
     */
    public function __construct(private readonly string $name, private readonly ?Throwable $refusal)
    {
    }

    /**
     * @param ContainedFailureSink $sink Master seam this server would report through
     */
    public function setContainedFailureSink(ContainedFailureSink $sink): void
    {
    }

    /**
     * @param ClientSocketDetacher $detacher Master seam this server would announce a departure to
     */
    public function setClientSocketDetacher(ClientSocketDetacher $detacher): void
    {
    }

    /**
     * @throws Throwable The refusal this server was built with, when it was built with one
     */
    public function prepareShutdown(): void
    {
        if ($this->refusal !== null) {
            throw $this->refusal;
        }

        $this->prepared = true;
    }

    /**
     * @return string Server name for logging
     */
    public function getServerName(): string
    {
        return $this->name;
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
    }

    /**
     * The one exit door, as the real servers spell it: closed, then handed back.
     *
     * @param ClientInterface $client Client to drop
     * @throws SocketException When closing the client's socket fails
     * @throws HilosException When the client fails to announce its close
     */
    public function dropClient(ClientInterface $client): void
    {
        try {
            $client->close();
        } finally {
            $this->removeClient($client);
        }
    }

    /**
     * @return ?ClientInterface Always null; the test never accepts a connection
     */
    public function acceptConnection(): ?ClientInterface
    {
        return null;
    }

    public function onTick(): void
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

final class DaemonManagerClusterReactionTestAgentManagerDaemon extends AgentManagerDaemon
{
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new AgentDaemonCreationFailedException('not used in test');
    }
}
