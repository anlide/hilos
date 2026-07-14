<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\ClusterContext;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Router\SignalRouter;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

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
}

final class DaemonManagerClusterReactionTestManager extends DaemonManager
{
    /** @var int Number of times onClusterWorkStop() fired */
    public int $workStopCount = 0;

    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new DaemonManagerClusterReactionTestAgentManagerDaemon();
    }

    public function onClusterWorkStop(): void
    {
        $this->workStopCount++;
    }
}

final class DaemonManagerClusterReactionTestAgentManagerDaemon extends AgentManagerDaemon
{
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new AgentDaemonCreationFailedException('not used in test');
    }
}
