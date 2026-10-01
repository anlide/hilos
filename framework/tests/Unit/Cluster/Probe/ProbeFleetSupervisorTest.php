<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Probe;

use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Peer\DTO\PeerPlacementReportDTO;
use Hilos\Cluster\Placement\ClusterPlacement;
use Hilos\Cluster\Placement\PlacementRecord;
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
 * tracked one alone, re-places a failed one once per interval, and does nothing at all where the
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
        $placement->onBecameLeader(microtime(true));
        $supervisor = new ProbeFleetSupervisor();

        $supervisor->tick();
        $this->assertSame([], $executor->executed, 'A fresh leader first adopts what the mesh already runs');

        $placement->onPlacementReport(self::PEER, new PeerPlacementReportDTO([]));
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

    public function testAFailedMemberIsRetriedOncePerInterval(): void
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
            'The retry window armed by the first pass must hold until it elapses',
        );

        new ReflectionProperty(ProbeFleetSupervisor::class, 'retryFailedAt')->setValue($supervisor, 0.0);
        $supervisor->tick();
        $this->assertSame([[HilosAgentType::HILOS_PROBE_FLEET, self::FAILED_INDEX]], $executor->executed);
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
