<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Probe;

use Hilos\Constants\TimeConstants;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Peer\DTO\PeerPlacementReportDTO;
use Hilos\Cluster\Placement\ClusterPlacement;
use Hilos\Cluster\Placement\PlacementRecord;
use Hilos\Cluster\Placement\PlacementExecutor;
use Hilos\Cluster\Placement\ResourceProfile;
use Hilos\Core\Daemon\AgentDeclarationDuty;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\EventLoop\EventLoopTimer;
use RuntimeException;
use Hilos\Cluster\Placement\PlacementState;
use Hilos\Cluster\Probe\ClusterProbe;
use Hilos\Cluster\Probe\ProbeFleetSupervisor;
use Hilos\Constants\HilosAgentType;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Tests\Unit\Cluster\Placement\FakePlacementExecutor;
use Hilos\Tests\Unit\Cluster\Placement\FakePlacementMesh;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * The leader's pass over the cluster probe fleet (HIL-1211).
 *
 * The framework's policy sweep leaves indexed pools to whoever declared them, and this pool is
 * the framework's: on every tick of the leader the supervisor keeps every member placed, leaves a
 * tracked one alone, delegates every failed retry to ClusterPlacement, and does nothing where the
 * fleet is not listed or no probe may start. A fresh leader's wait for its rebuild is the
 * placement coordinator's (HIL-1217), so the supervisor holds no window of its own.
 */
final class ProbeFleetSupervisorTest extends TestCase
{
    private const string SELF = 'leader';

    /** @var string A node online beside this one that declares nothing, so it is never a fit */
    private const string PEER = 'peer';

    private const string FAILED_INDEX = '4';

    /** @var class-string<Hilos> App class bound before this test touched it */
    private string $boundAppClass;

    private ?EnvAccessor $previousEnv = null;

    private ?ClusterContext $previousCluster = null;

    /** @var string|false APP_ENV the suite runs under, put back so this file does not decide what the next one reads */
    private string|false $previousAppEnv = false;

    protected function setUp(): void
    {
        $this->boundAppClass = Hilos::appClass();
        $this->previousEnv = Hilos::$env;
        $this->previousCluster = Hilos::$cluster;
        $this->previousAppEnv = getenv('APP_ENV');
        $this->bindAppClass(ProbeFleetTestHilos::class);
        putenv('CLUSTER_ENABLED=true');
        putenv('APP_ENV=test');
        Hilos::$env = new EnvAccessor();
    }

    protected function tearDown(): void
    {
        $this->bindAppClass($this->boundAppClass);
        Hilos::$env = $this->previousEnv;
        Hilos::$cluster = $this->previousCluster;
        putenv('CLUSTER_ENABLED');
        $this->previousAppEnv === false ? putenv('APP_ENV') : putenv('APP_ENV=' . $this->previousAppEnv);

        parent::tearDown();
    }

    public function testEveryMemberIsPlacedOnTheLeadersTick(): void
    {
        $executor = $this->installPlacement();

        new ProbeFleetSupervisor()->tick();

        $this->assertSame(self::fleet(), $executor->executed);
    }

    public function testNothingIsPlacedUntilTheFreshLeadersRebuildSettles(): void
    {
        $executor = $this->installPlacement([self::SELF, self::PEER]);
        $placement = Hilos::$cluster?->placement();
        $this->assertNotNull($placement);
        $placement->onBecameLeader((hrtime(true) / TimeConstants::NS_PER_MILLISECOND / TimeConstants::MS_PER_SECOND));
        $supervisor = new ProbeFleetSupervisor();

        $supervisor->tick();
        $this->assertSame([], $executor->executed, 'A fresh leader first adopts what the mesh already runs');

        $placement->onPlacementReport(self::PEER, new PeerPlacementReportDTO([]));
        $supervisor->tick();
        $this->assertSame([], $executor->executed, 'The final report still waits for snapshot inheritance on tick');
        $placement->setHoldWake($supervisor->noteChanged(...));
        $placement->tick((hrtime(true) / TimeConstants::NS_PER_MILLISECOND / TimeConstants::MS_PER_SECOND));
        $supervisor->tick();
        $this->assertSame(self::fleet(), $executor->executed, 'Every member is placed once every node has reported');
    }

    public function testATrackedMemberIsLeftAlone(): void
    {
        $executor = $this->installPlacement();
        $supervisor = new ProbeFleetSupervisor();

        $supervisor->tick();
        $supervisor->tick();

        $this->assertCount(ClusterProbe::FLEET_SIZE, $executor->executed, 'A record in a live state suppresses re-placing');
    }

    public function testExplicitlyCanceledMemberIsNotDeclaredMissing(): void
    {
        $executor = $this->installPlacement();
        $supervisor = new ProbeFleetSupervisor();
        $supervisor->tick();
        $executor->executed = [];
        $placement = Hilos::$cluster->placement();
        $placement->stopAgentOnNode(HilosAgentType::HILOS_PROBE_FLEET, '3', self::SELF);

        $supervisor->tick();
        $supervisor->tick();

        $this->assertSame([], $executor->executed);
        $this->assertCount(10, $placement->registry()->all());
        $this->assertEquals(
            new PlacementRecord(HilosAgentType::HILOS_PROBE_FLEET, '3', null, PlacementState::Stopped),
            $placement->registry()->get(HilosAgentType::HILOS_PROBE_FLEET . ':3'),
        );
    }

    public function testAFailedMemberIsLeftToTheFrameworkRetry(): void
    {
        $executor = $this->installPlacement();
        $supervisor = new ProbeFleetSupervisor();
        $supervisor->tick();
        $executor->executed = [];

        $this->failMember();
        $supervisor->tick();
        $this->assertSame(
            [],
            $executor->executed,
            'The coordinator retains a tracked failed member',
        );

        $supervisor->tick();
        $this->assertSame([], $executor->executed, 'The supervisor only declares absent members');
        $this->assertNull(Hilos::$cluster->placement()->registry()->get(HilosAgentType::HILOS_PROBE_FLEET . ':10'));
        $this->assertCount(10, Hilos::$cluster->placement()->registry()->all());
    }

    public function testExtraIndexesAreNeitherRetriedNorRecreatedByTheSupervisor(): void
    {
        $executor = $this->installPlacement();
        $supervisor = new ProbeFleetSupervisor();
        $supervisor->tick();
        $executor->executed = [];
        $placement = Hilos::$cluster->placement();
        $placement->registry()->put(new PlacementRecord(HilosAgentType::HILOS_PROBE_FLEET, '99', null, PlacementState::Failed));
        $supervisor->tick();
        $this->assertCount(11, $placement->registry()->all());
        $this->assertSame([], $executor->executed);
        $placement->registry()->forget(HilosAgentType::HILOS_PROBE_FLEET . ':99');
        $supervisor->tick();
        $this->assertCount(10, $placement->registry()->all());
        $this->assertNull($placement->registry()->get(HilosAgentType::HILOS_PROBE_FLEET . ':99'));
        $this->assertSame([], $executor->executed);
    }

    public function testNothingIsPlacedWhereNoProbeMayStart(): void
    {
        $executor = $this->installPlacement();
        putenv('APP_ENV=prod');
        Hilos::$env = new EnvAccessor();

        new ProbeFleetSupervisor()->tick();

        $this->assertSame([], $executor->executed);
    }

    public function testNothingIsPlacedWhenTheProjectDoesNotListTheFleet(): void
    {
        $executor = $this->installPlacement();
        $this->bindAppClass(NoFleetTestHilos::class);

        new ProbeFleetSupervisor()->tick();

        $this->assertSame([], $executor->executed);
    }

    /**
     * @return list<array{string, string}> Every fleet member, as the executor records a placement
     */
    private static function fleet(): array
    {
        $fleet = [];
        for ($index = 0; $index < ClusterProbe::FLEET_SIZE; $index++) {
            $fleet[] = [HilosAgentType::HILOS_PROBE_FLEET, (string)$index];
        }

        return $fleet;
    }

    /**
     * Mounts a cluster context holding a real placement coordinator over fake ports.
     *
     * The local node is the only one that advertises the worker capability, so best-fit picks it
     * and the placement runs the local start path - which is the executor this returns.
     *
     * @param list<string> $online Node ids the mesh reports online
     * @return FakePlacementExecutor Executor recording what the placement path launched
     */
    private function installPlacement(array $online = [self::SELF]): FakePlacementExecutor
    {
        $executor = new FakePlacementExecutor([ClusterProbe::CAPABILITY_WORKER]);
        $context = new ClusterContext();
        $context->registerPlacement(new ClusterPlacement(
            self::SELF,
            new FakePlacementMesh([self::SELF => [ClusterProbe::CAPABILITY_WORKER, 'slots=10']], online: $online),
            $executor,
        ));
        Hilos::$cluster = $context;

        return $executor;
    }

    /** A reentrant cancellation supersedes a failed attempt before it can leave a tracked failure. */
    public function testUntrackedExceptionKeepsSuffixUntilItsOwnNativeRetry(): void
    {
        $executed = [];
        $refuse = true;
        $placement = null;
        $executor = $this->createMock(PlacementExecutor::class);
        $executor->method('requiredCapabilities')->willReturnCallback(
            static function (string $type, ?string $index) use (&$placement, &$refuse): array {
                if ($index === '3' && $refuse) {
                    $refuse = false;
                    $placement->stopAgentOnNode($type, $index, null);
                    $placement->registry()->forget($type . ':' . $index);
                    throw new RuntimeException('declaration superseded before tracking');
                }
                return [];
            },
        );
        $executor->method('placementProfile')->willReturn(ResourceProfile::none());
        $executor->method('executePlacement')->willReturnCallback(
            static function (string $type, ?string $index) use (&$executed): int {
                $executed[] = [$type, $index];
                return 0;
            },
        );
        $executor->method('placedWorkerId')->willReturn(0);
        $placement = new ClusterPlacement(self::SELF, new FakePlacementMesh([self::SELF => ['slots=10']], online: [self::SELF]), $executor);
        Hilos::$cluster = new ClusterContext();
        Hilos::$cluster->registerPlacement($placement);
        $supervisor = new ProbeFleetSupervisor();
        $supervisor->tick();
        $prefix = [[HilosAgentType::HILOS_PROBE_FLEET, '0'], [HilosAgentType::HILOS_PROBE_FLEET, '1'],
            [HilosAgentType::HILOS_PROBE_FLEET, '2']];
        self::assertSame($prefix, $executed);
        self::assertNull($placement->registry()->get(HilosAgentType::HILOS_PROBE_FLEET . ':3'));
        $supervisor->tick();
        self::assertSame($prefix, $executed);
        $duty = new ReflectionProperty(ProbeFleetSupervisor::class, 'declarations')->getValue($supervisor);
        $retry = new ReflectionProperty(AgentDeclarationDuty::class, 'retryAt');
        $original = $retry->getValue($duty);
        self::assertIsFloat($original);
        // Age the actual attempt's basis before binding its native deadline; no timer is shortened.
        $retry->setValue($duty, $original - 6.0);
        $loop = new EventLoop();
        try {
            $supervisor->attachEventLoop($loop);
            $timer = new ReflectionProperty(AgentDeclarationDuty::class, 'retryTimer')->getValue($duty);
            self::assertInstanceOf(EventLoopTimer::class, $timer);
            self::assertSame($original - 6.0, $timer->getDeadline());
            $loop->runOnce();
            self::assertSame($prefix, $executed, 'native due only cues the guarded supervisor entry');
            self::assertTrue($supervisor->hasReadyWork());
            $supervisor->tick();
            self::assertSame(self::fleet(), $executed);
            self::assertFalse($supervisor->hasReadyWork());
            self::assertNull(new ReflectionProperty(AgentDeclarationDuty::class, 'retryTimer')->getValue($duty));
        } finally {
            $supervisor->detachEventLoop();
            $loop->cleanup();
        }
    }

    private function failMember(): void
    {
        Hilos::$cluster?->placement()?->registry()->put(
            new PlacementRecord(HilosAgentType::HILOS_PROBE_FLEET, self::FAILED_INDEX, self::SELF, PlacementState::Failed),
        );
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
 * Project facade listing the probe fleet and nothing else.
 *
 * Abstract because only its registry constant is read.
 */
abstract class ProbeFleetTestHilos extends Hilos
{
    public const array AGENTS = [
        HilosAgentType::HILOS_PROBE_FLEET => ClusterProbe::AGENTS[HilosAgentType::HILOS_PROBE_FLEET],
    ];
}

/**
 * Project facade listing no probe at all.
 *
 * Abstract because only its registry constant is read.
 */
abstract class NoFleetTestHilos extends Hilos
{
    public const array AGENTS = [];
}
