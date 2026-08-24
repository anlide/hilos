<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Leadership;
use Hilos\Cluster\PendingLeadership;
use Hilos\Cluster\StandaloneLeadership;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\DTO\AgentMessageDTOInterface;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Agent\Exception\AgentNotLinkedToWorkerException;
use Hilos\Core\Agent\Exception\NoSuitableWorkerException;
use Hilos\Hilos;
use Hilos\Socket\Server\WorkerServer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * Unit tests for the placement gate in WorkerServer::startAgent() (HIL-340, HIL-667).
 *
 * The gate reads the agent's two registry axes and nothing else. A leader-hosted cluster
 * singleton is refused on a follower, an every-node replica is not, and a policy-placed
 * cluster singleton is refused on a follower unless the start arrives down the placement
 * path — which is what keeps "exactly one cluster-wide" a mechanism rather than a promise the
 * callers keep. Standalone nodes are always the leader, so the gate is transparent
 * off-cluster.
 */
final class WorkerServerLeadershipGateTest extends TestCase
{
    private const string LEADER_TYPE = 'leader';

    private const string PER_NODE_TYPE = 'pernode';

    private const string POLICY_TYPE = 'policy';

    /** @var class-string<Hilos> App class bound before this test touched it */
    private string $boundAppClass;

    protected function setUp(): void
    {
        $this->boundAppClass = Hilos::appClass();
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, GateTestHilos::class);
    }

    protected function tearDown(): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->boundAppClass);
        Hilos::$cluster = null;

        parent::tearDown();
    }

    public function testLeaderOnlyAgentIsNotStartedOnAFollower(): void
    {
        $manager = new GateTestAgentManagerDaemon();
        $server = $this->buildServer($manager);
        $this->installLeadership(new PendingLeadership());

        // No exception: the gate returns before worker selection.
        $server->startAgentPublic(self::LEADER_TYPE);

        $this->assertFalse(
            $manager->hasAgent(self::LEADER_TYPE),
            'A gated start must roll back the temporary agent record so a later promotion starts it cleanly.',
        );
    }

    public function testLeaderOnlyAgentPassesTheGateWhenLeader(): void
    {
        $server = $this->buildServer(new GateTestAgentManagerDaemon());
        $this->installLeadership(new StandaloneLeadership());

        // Passing the gate reaches worker selection, which has no workers registered.
        $this->expectException(NoSuitableWorkerException::class);
        $server->startAgentPublic(self::LEADER_TYPE);
    }

    public function testPerNodeAgentPassesTheGateOnAFollower(): void
    {
        $server = $this->buildServer(new GateTestAgentManagerDaemon());
        $this->installLeadership(new PendingLeadership());

        // A replica exists on every node, so the gate has nothing to decide.
        $this->expectException(NoSuitableWorkerException::class);
        $server->startAgentPublic(self::PER_NODE_TYPE);
    }

    public function testPolicyPlacedAgentIsRefusedOnAFollowerWhenStartedDirectly(): void
    {
        $manager = new GateTestAgentManagerDaemon();
        $server = $this->buildServer($manager);
        $this->installLeadership(new PendingLeadership());

        // A direct start is exactly the case the gate closes: an inbound signal, a bootstrap
        // list or a project loop would otherwise raise a second instance beside the placed one.
        $server->startAgentPublic(self::POLICY_TYPE);

        $this->assertFalse($manager->hasAgent(self::POLICY_TYPE), 'A refused start must leave no agent record behind.');
    }

    public function testPolicyPlacedAgentPassesTheGateOnAFollowerWhenPlacementBringsIt(): void
    {
        $server = $this->buildServer(new GateTestAgentManagerDaemon());
        $this->installLeadership(new PendingLeadership());

        // Placement is the one entry that carries the leader's sanction, so it passes where a
        // direct start does not; worker selection then fails with no workers registered.
        $this->expectException(NoSuitableWorkerException::class);
        $server->executePlacement(self::POLICY_TYPE, null);
    }

    public function testLeaderOnlyAgentIsRefusedOnAFollowerEvenDownThePlacementPath(): void
    {
        $manager = new GateTestAgentManagerDaemon();
        $server = $this->buildServer($manager);
        $this->installLeadership(new PendingLeadership());

        // The sanction only relaxes the policy cell: a leader-hosted singleton follows
        // leadership, and placement has no say over where leadership currently sits. The gate
        // returns quietly, so what reaches the caller is the missing worker link.
        try {
            $server->executePlacement(self::LEADER_TYPE, null);
            $this->fail('The gate was expected to refuse a leader-hosted singleton on a follower.');
        } catch (AgentNotLinkedToWorkerException) {
            $this->assertFalse($manager->hasAgent(self::LEADER_TYPE));
        }
    }

    public function testStandaloneWithoutClusterContextStartsTheAgent(): void
    {
        $server = $this->buildServer(new GateTestAgentManagerDaemon());
        Hilos::$cluster = null;

        // With no cluster context the node is its own leader, so the start proceeds.
        $this->expectException(NoSuitableWorkerException::class);
        $server->startAgentPublic(self::LEADER_TYPE);
    }

    /**
     * @param AgentManagerDaemon $manager Agent manager the server routes starts through
     * @return GateTestWorkerServer Server holding that manager
     */
    private function buildServer(AgentManagerDaemon $manager): GateTestWorkerServer
    {
        // Skip the constructor: it reads worker env and creates a log directory that
        // this gate test does not exercise. Only the agent manager is needed.
        $server = new ReflectionClass(GateTestWorkerServer::class)->newInstanceWithoutConstructor();

        $property = new ReflectionProperty(WorkerServer::class, 'agentManager');
        $property->setValue($server, $manager);

        return $server;
    }

    /**
     * @param Leadership $leadership Leadership seam the node reads its own role from
     */
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
    /**
     * @param string $agentType Agent type to start
     * @param ?string $agentIndex Agent index to start
     * @throws AgentDaemonCreationFailedException If the agent daemon cannot be created
     * @throws NoSuitableWorkerException When the gate opens and no worker is registered
     */
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
 * Agent manager whose factory answers every type this test declares.
 */
final class GateTestAgentManagerDaemon extends AgentManagerDaemon
{
    /**
     * @param string $agentType Agent type that was asked for
     * @param ?string $agentIndex Agent index that was asked for
     * @return AgentDaemonInterface Daemon carrying nothing but its index
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        return new GateTestAgentDaemon($agentIndex);
    }
}

/**
 * Agent daemon that declares nothing, so only the registry axes decide its fate.
 */
final class GateTestAgentDaemon extends AbstractAgentDaemon
{
    public function __construct(?string $agentIndex)
    {
        $this->agentIndex = $agentIndex;
    }

    public function requiresMonopolisticProcess(): bool
    {
        return false;
    }

    /**
     * @param AgentMessageDTOInterface $message Message that would go to a user; unused here
     */
    public function sendToUser(AgentMessageDTOInterface $message): void
    {
        // Not used in this test
    }
}

/**
 * Project facade whose registry declares one agent per live cell of the placement matrix.
 *
 * Abstract because only its registry constant is read: the gate never builds a database.
 */
abstract class GateTestHilos extends Hilos
{
    public const array AGENTS = [
        'leader' => [],
        'pernode' => [AgentRegistryKey::SCOPE => AgentScope::NODE],
        'policy' => [AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY],
    ];
}
