<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Placement\ClusterPlacement;
use Hilos\Cluster\Placement\PlacementRecord;
use Hilos\Cluster\Placement\PlacementState;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Router\SignalRouter;
use Hilos\Hilos;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Tests\Unit\Cluster\Placement\FakePlacementExecutor;
use Hilos\Tests\Unit\Cluster\Placement\FakePlacementMesh;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Unit tests for the leader's placement pass over policy-placed agents (HIL-667).
 *
 * The framework half of the CLUSTER+POLICY cell: nothing else asks for such an agent, because
 * the start gate refuses it everywhere but the node placement chose. The pass reconciles on
 * every leader tick rather than ensuring once, since a placement can legitimately find no
 * capable node yet; it leaves indexed pools to the project that knows their members; and with
 * cluster mode off it lands the agent on the single node, which is its own leader.
 */
final class DaemonManagerPolicyPlacementTest extends TestCase
{
    private const string SELF = 'leader';

    private const string POLICY_TYPE = 'library';

    /** @var class-string<Hilos> App class bound before this test touched it */
    private string $boundAppClass;

    protected function setUp(): void
    {
        $this->boundAppClass = Hilos::appClass();
        $this->bindAppClass(PolicyPlacementTestHilos::class);
    }

    protected function tearDown(): void
    {
        $this->bindAppClass($this->boundAppClass);
        Hilos::$sr = null;
        Hilos::$cluster = null;

        parent::tearDown();
    }

    public function testTheLeaderPlacesOnlyTheUnindexedPolicyAgents(): void
    {
        $manager = new PolicyPlacementTestManager();
        $executor = $this->installPlacement();

        $this->invokeEnsurePolicyAgentsPlaced($manager);

        $this->assertSame(
            [[self::POLICY_TYPE, null]],
            $executor->executed,
            'A leader-hosted singleton, an every-node replica and an indexed pool are none of this pass'
            . ' business: the first two need no placing, and only the project knows a pool members.',
        );
    }

    public function testATrackedPlacementIsNotPlacedAgain(): void
    {
        $manager = new PolicyPlacementTestManager();
        $executor = $this->installPlacement();

        $this->invokeEnsurePolicyAgentsPlaced($manager);
        $this->invokeEnsurePolicyAgentsPlaced($manager);

        $this->assertCount(1, $executor->executed, 'A record in a live state must suppress re-placing.');
    }

    public function testAFailedPlacementIsRetriedOncePerInterval(): void
    {
        $manager = new PolicyPlacementTestManager();
        $executor = $this->installPlacement();
        Hilos::$cluster?->placement()?->registry()->put(
            new PlacementRecord(self::POLICY_TYPE, null, self::SELF, PlacementState::Failed),
        );

        // Nothing else retries a failed placement, so the first pass through the open retry
        // window re-places it - and re-arming the window is what keeps a node that cannot host
        // it from being asked again on every iteration of the daemon loop.
        $this->invokeEnsurePolicyAgentsPlaced($manager);
        $this->assertCount(1, $executor->executed);

        Hilos::$cluster?->placement()?->registry()->put(
            new PlacementRecord(self::POLICY_TYPE, null, self::SELF, PlacementState::Failed),
        );
        $this->invokeEnsurePolicyAgentsPlaced($manager);
        $this->assertCount(1, $executor->executed, 'The retry window must hold until it elapses.');
    }

    public function testWithClusterModeOffTheSingleNodeHostsItItself(): void
    {
        $manager = new PolicyPlacementTestManager();
        $server = $this->registerRecordingWorkerServer($manager);
        $this->setWorkersReady($manager, true);
        Hilos::$cluster = null;

        $this->invokeEnsurePolicyAgentsPlaced($manager);

        $this->assertSame(
            [[self::POLICY_TYPE, null]],
            $server->placed,
            'A single node is its own leader and its own data plane, so the declaration lands here.',
        );
    }

    public function testWithClusterModeOffNothingIsHostedUntilWorkersAreReady(): void
    {
        $manager = new PolicyPlacementTestManager();
        $server = $this->registerRecordingWorkerServer($manager);
        $this->setWorkersReady($manager, false);
        Hilos::$cluster = null;

        $this->invokeEnsurePolicyAgentsPlaced($manager);

        $this->assertSame([], $server->placed, 'There is nothing to place until a worker can take it.');
    }

    /**
     * Mounts a cluster context holding a real placement coordinator over fake ports.
     *
     * The local node is the only online one, so best-fit picks it and the placement runs the
     * local start path - which is the executor this returns.
     *
     * @return FakePlacementExecutor Executor recording what the placement path launched
     */
    private function installPlacement(): FakePlacementExecutor
    {
        $executor = new FakePlacementExecutor();
        $context = new ClusterContext();
        $context->registerPlacement(new ClusterPlacement(
            self::SELF,
            new FakePlacementMesh([self::SELF => []], online: [self::SELF]),
            $executor,
        ));
        Hilos::$cluster = $context;

        return $executor;
    }

    /**
     * @param DaemonManager $manager Manager to register the server on
     * @return PolicyPlacementRecordingWorkerServer Server recording local placements
     */
    private function registerRecordingWorkerServer(DaemonManager $manager): PolicyPlacementRecordingWorkerServer
    {
        $server = new ReflectionClass(PolicyPlacementRecordingWorkerServer::class)->newInstanceWithoutConstructor();
        $manager->registerServer($server);

        return $server;
    }

    /**
     * @param DaemonManager $manager Manager to write the flag on
     * @param bool $ready Whether this node reports its workers ready
     */
    private function setWorkersReady(DaemonManager $manager, bool $ready): void
    {
        new ReflectionProperty(DaemonManager::class, 'workersReady')->setValue($manager, $ready);
    }

    /**
     * @param DaemonManager $manager Manager to run one placement pass on
     */
    private function invokeEnsurePolicyAgentsPlaced(DaemonManager $manager): void
    {
        new ReflectionMethod(DaemonManager::class, 'ensurePolicyAgentsPlaced')->invoke($manager);
    }

    /**
     * @param class-string<Hilos> $hilosClass App class to bind as the topology source
     */
    private function bindAppClass(string $hilosClass): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $hilosClass);
    }
}

/**
 * Daemon manager with the factory wiring the base needs and nothing else.
 */
final class PolicyPlacementTestManager extends DaemonManager
{
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new PolicyPlacementTestAgentManagerDaemon();
    }
}

/**
 * Agent manager that is never asked for a daemon in these cases.
 */
final class PolicyPlacementTestAgentManagerDaemon extends AgentManagerDaemon
{
    /**
     * @param string $agentType Agent type that was asked for
     * @param ?string $agentIndex Agent index that was asked for
     * @return AgentDaemonInterface Never returned; these cases start no agent
     * @throws AgentDaemonCreationFailedException Always
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new AgentDaemonCreationFailedException('not used in test');
    }
}

/**
 * Worker server that records local placements instead of reaching for a worker.
 */
final class PolicyPlacementRecordingWorkerServer extends WorkerServer
{
    /** @var list<array{0: string, 1: ?string}> Locally placed agents, as [type, index] */
    public array $placed = [];

    /**
     * @param string $agentType Agent type placed on this node
     * @param ?string $agentIndex Agent index placed on this node
     * @return int Worker id a successful placement lands on
     */
    public function executePlacement(string $agentType, ?string $agentIndex): int
    {
        $this->placed[] = [$agentType, $agentIndex];

        return 1;
    }

    protected function onStart(): void
    {
        // Not used in this test
    }
}

/**
 * Project facade declaring one agent per cell the pass has to tell apart.
 *
 * Abstract because only its registry constant is read: the pass never builds a database.
 */
abstract class PolicyPlacementTestHilos extends Hilos
{
    public const array AGENTS = [
        'chat' => [],
        'presence' => [AgentRegistryKey::SCOPE => AgentScope::NODE],
        'library' => [AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY],
        'mail' => [
            AgentRegistryKey::INDEXED => true,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
    ];
}
