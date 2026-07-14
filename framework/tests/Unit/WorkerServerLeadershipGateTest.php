<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Leadership;
use Hilos\Cluster\PendingLeadership;
use Hilos\Cluster\StandaloneLeadership;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\DTO\AgentMessageDTOInterface;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Agent\Exception\NoSuitableWorkerException;
use Hilos\Hilos;
use Hilos\Socket\Server\WorkerServer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * Unit tests for the cluster-leadership gate in WorkerServer::startAgent() (HIL-340).
 *
 * The gate refuses to start a leader-only agent on a non-leader node, covering both
 * the bootstrap-list path and direct project starts. Standalone nodes are always the
 * leader, so the gate is transparent off-cluster.
 */
final class WorkerServerLeadershipGateTest extends TestCase
{
    public function tearDown(): void
    {
        Hilos::$cluster = null;

        parent::tearDown();
    }

    public function testLeaderOnlyAgentIsNotStartedOnAFollower(): void
    {
        $manager = new GateTestAgentManagerDaemon();
        $server = $this->buildServer($manager);
        $this->installLeadership(new PendingLeadership());

        // No exception: the gate returns before worker selection.
        $server->startAgentPublic('leader');

        $this->assertFalse(
            $manager->hasAgent('leader'),
            'A gated start must roll back the temporary agent record so a later promotion starts it cleanly.',
        );
    }

    public function testLeaderOnlyAgentPassesTheGateWhenLeader(): void
    {
        $manager = new GateTestAgentManagerDaemon();
        $server = $this->buildServer($manager);
        $this->installLeadership(new StandaloneLeadership());

        // Passing the gate reaches worker selection, which has no workers registered.
        $this->expectException(NoSuitableWorkerException::class);
        $server->startAgentPublic('leader');
    }

    public function testPerNodeAgentPassesTheGateOnAFollower(): void
    {
        $manager = new GateTestAgentManagerDaemon();
        $server = $this->buildServer($manager);
        $this->installLeadership(new PendingLeadership());

        // The per-node agent opts out of the gate, so it reaches worker selection.
        $this->expectException(NoSuitableWorkerException::class);
        $server->startAgentPublic('pernode');
    }

    public function testStandaloneWithoutClusterContextStartsTheAgent(): void
    {
        $manager = new GateTestAgentManagerDaemon();
        $server = $this->buildServer($manager);
        Hilos::$cluster = null;

        // With no cluster context the node is its own leader, so the start proceeds.
        $this->expectException(NoSuitableWorkerException::class);
        $server->startAgentPublic('leader');
    }

    private function buildServer(AgentManagerDaemon $manager): GateTestWorkerServer
    {
        // Skip the constructor: it reads worker env and creates a log directory that
        // this gate test does not exercise. Only the agent manager is needed.
        $server = (new ReflectionClass(GateTestWorkerServer::class))->newInstanceWithoutConstructor();

        $property = new ReflectionProperty(WorkerServer::class, 'agentManager');
        $property->setValue($server, $manager);

        return $server;
    }

    private function installLeadership(Leadership $leadership): void
    {
        $context = new ClusterContext();
        $context->registerLeadership($leadership);
        Hilos::$cluster = $context;
    }
}

/**
 * Worker server that exposes the protected startAgent() for the gate test.
 */
final class GateTestWorkerServer extends WorkerServer
{
    public function startAgentPublic(string $agentType, ?string $agentIndex = null): void
    {
        $this->startAgent($agentType, $agentIndex);
    }

    protected function onStart(): void
    {
        // Not used in this test
    }
}

/**
 * Agent manager whose factory returns a leader-only or an opt-out daemon by type.
 */
final class GateTestAgentManagerDaemon extends AgentManagerDaemon
{
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        return match ($agentType) {
            'leader' => new GateTestAgentDaemon(true, $agentIndex),
            'pernode' => new GateTestAgentDaemon(false, $agentIndex),
            default => throw new AgentDaemonCreationFailedException($agentType, $agentIndex),
        };
    }
}

/**
 * Agent daemon whose leadership requirement is fixed at construction.
 */
final class GateTestAgentDaemon extends AbstractAgentDaemon
{
    public function __construct(private readonly bool $leaderOnly, ?string $agentIndex)
    {
        $this->agentIndex = $agentIndex;
    }

    public function requiresMonopolisticProcess(): bool
    {
        return false;
    }

    public function requiresClusterLeadership(): bool
    {
        return $this->leaderOnly;
    }

    public function sendToUser(AgentMessageDTOInterface $message): void
    {
        // Not used in this test
    }
}
