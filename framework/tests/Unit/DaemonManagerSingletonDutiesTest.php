<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Leadership;
use Hilos\Cluster\PendingLeadership;
use Hilos\Cluster\StandaloneLeadership;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Router\SignalRouter;
use Hilos\Hilos;
use Hilos\Socket\Server\WorkerServer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Unit tests for the leader-gated singleton duties on the daemon (HIL-340):
 * the ensure-once that starts cluster-singleton agents, and the amLeader() gate that
 * keeps cron / readiness / singleton-start on the leader (or standalone) node only.
 */
final class DaemonManagerSingletonDutiesTest extends TestCase
{
    public function tearDown(): void
    {
        Hilos::$sr = null;
        Hilos::$cluster = null;

        parent::tearDown();
    }

    public function testSingletonStartFiresExactlyOncePerTermWhenWorkersReady(): void
    {
        $manager = new SingletonDutiesTestManager();
        $server = $this->registerRecordingWorkerServer($manager);
        $this->setWorkersReady($manager, true);

        $this->invokeEnsureSingletonsStarted($manager);
        $this->invokeEnsureSingletonsStarted($manager);

        $this->assertSame(1, $server->singletonHostCalls, 'The ensure-once must start singletons once per leadership term.');
    }

    public function testSingletonStartWaitsForWorkers(): void
    {
        $manager = new SingletonDutiesTestManager();
        $server = $this->registerRecordingWorkerServer($manager);
        $this->setWorkersReady($manager, false);

        $this->invokeEnsureSingletonsStarted($manager);

        $this->assertSame(0, $server->singletonHostCalls, 'Singletons must not start until this node reports workers ready.');
    }

    public function testStandaloneNodeIsItsOwnLeader(): void
    {
        $manager = new SingletonDutiesTestManager();

        // No cluster context: cluster mode is off, the node runs every duty.
        Hilos::$cluster = null;
        $this->assertTrue($this->invokeAmLeader($manager));

        $this->installLeadership(new StandaloneLeadership());
        $this->assertTrue($this->invokeAmLeader($manager));
    }

    public function testFollowerIsNotLeader(): void
    {
        $manager = new SingletonDutiesTestManager();
        $this->installLeadership(new PendingLeadership());

        $this->assertFalse(
            $this->invokeAmLeader($manager),
            'A clustered node without leadership must skip singleton duties (cron, readiness, singleton start).',
        );
    }

    private function registerRecordingWorkerServer(DaemonManager $manager): RecordingWorkerServer
    {
        $server = new ReflectionClass(RecordingWorkerServer::class)->newInstanceWithoutConstructor();
        $manager->registerServer($server);

        return $server;
    }

    private function setWorkersReady(DaemonManager $manager, bool $ready): void
    {
        new ReflectionProperty(DaemonManager::class, 'workersReady')->setValue($manager, $ready);
    }

    private function invokeEnsureSingletonsStarted(DaemonManager $manager): void
    {
        new ReflectionMethod(DaemonManager::class, 'ensureSingletonsStarted')->invoke($manager);
    }

    private function invokeAmLeader(DaemonManager $manager): bool
    {
        return new ReflectionMethod(DaemonManager::class, 'amLeader')->invoke($manager);
    }

    private function installLeadership(Leadership $leadership): void
    {
        $context = new ClusterContext();
        $context->registerLeadership($leadership);
        Hilos::$cluster = $context;
    }
}

final class SingletonDutiesTestManager extends DaemonManager
{
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new SingletonDutiesTestAgentManagerDaemon();
    }
}

final class SingletonDutiesTestAgentManagerDaemon extends AgentManagerDaemon
{
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new AgentDaemonCreationFailedException('not used in test');
    }
}

/**
 * Worker server that counts singleton-host activations instead of starting agents.
 */
final class RecordingWorkerServer extends WorkerServer
{
    /** @var int Number of times the leader-gated ensure-once fired the singleton-host hook */
    public int $singletonHostCalls = 0;

    public function onBecameSingletonHost(): void
    {
        $this->singletonHostCalls++;
    }

    protected function onStart(): void
    {
        // Not used in this test
    }
}
