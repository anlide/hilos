<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\NodeRole;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Hilos\DaemonCollectorAgent;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Feature\Definition\StatusFeature;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\DaemonSection\ClusterDaemonNodeSlot;
use Hilos\DaemonSection\ClusterDaemonNodeView;
use Hilos\DaemonSection\DaemonAgentPicture;
use Hilos\DaemonSection\DaemonProcessRoster;
use Hilos\DaemonSection\DaemonWorkerPicture;
use Hilos\DaemonSection\DTO\DaemonClusterPicturePortionSignalData;
use Hilos\DaemonSection\DTO\DaemonPictureWatchSignalData;
use Hilos\DaemonSection\NodeDaemonPicture;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Object\Collection\StatusIncidents as ObjectStatusIncidents;
use Hilos\Database\View\Collection\StatusIncidents;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Worker\DTO\WorkerAgentStartedDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentStoppedDTO;
use Hilos\Status\StatusAgent;
use Hilos\Status\StatusComponentDefinition;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

require_once __DIR__ . '/StatusComponentBootstrapTestSupport.php';

/** Independent Status lease and whole-picture normalization, with explicit local and foreign clocks. */
final class StatusObservationTest extends TestCase
{
    private string $previousApp;
    private ?RtContext $previousRuntime;
    private ?SignalRouter $previousRouter;
    private ?HilosDbContext $previousDb;
    private StatusObservationRuntime $runtime;
    private StatusAgent $owner;
    private int $startedAt;

    protected function setUp(): void
    {
        $this->previousApp = Hilos::appClass();
        $this->previousRuntime = Hilos::$rt;
        $this->previousRouter = Hilos::$sr;
        $this->previousDb = Hilos::$db;
        (new ReflectionProperty(Hilos::class, 'appClass'))->setValue(null, StatusObservationHilos::class);
        Hilos::$sr = new SignalRouter();
        Hilos::$db = new StatusObservationDbContext();
        Hilos::$db->configure();
        $this->runtime = new StatusObservationRuntime();
        $this->runtime->mountFeatureRuntime([new StatusFeature()]);
        $this->runtime->configure();
        $this->runtime->bindStateCollectionNames();
        Hilos::$rt = $this->runtime;
        $this->owner = new StatusAgent();
        OwnershipDeclaration::claimAll($this->owner);
        ExecutionContext::setCurrentAgentId($this->owner->getId());
        $this->owner->onStart();
        $this->startedAt = (new ReflectionProperty(StatusAgent::class, 'lastWatchAt'))->getValue($this->owner);
    }

    protected function tearDown(): void
    {
        RtTruthSourceRegistry::unregisterAgent($this->owner->getId());
        TruthSourceRegistry::unregisterAgent($this->owner->getId());
        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->owner->getId()));
        ExecutionContext::setCurrentAgentId(null);
        Hilos::$sr = $this->previousRouter;
        Hilos::$db = $this->previousDb;
        Hilos::$rt = $this->previousRuntime;
        (new ReflectionProperty(Hilos::class, 'appClass'))->setValue(null, $this->previousApp);
        parent::tearDown();
    }

    /** Bootstrap acknowledgement feeds the ordinary roster builder; only a subsequent usable report proves presence. */
    public function testAcknowledgedCatalogBootstrapRemainsUnknownUntilFreshRosterAndThenDetectsStop(): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, StatusObservationBootstrapHilos::class);
        [$server, $manager, $worker] = StatusComponentBootstrapTestSupport::buildServer();
        $server->onBecameSingletonHost();
        self::assertSame(['probe'], $worker->startIds());
        $worker->report(new WorkerAgentStartedDTO('probe', 'probe'));
        self::assertTrue($manager->isAgentStarted('probe'));
        $component = Hilos::statusComponents()->getIterator()['service'];
        $running = StatusComponentBootstrapTestSupport::roster($server, $manager);
        self::assertSame('probe', $running->workers[0]->agents[0]->id);
        $this->apply(true, [new ClusterDaemonNodeView('n1', true, new ClusterDaemonNodeSlot(
            'n1', new NodeDaemonPicture('n1', NodeRole::Master, 9999999999, $running), 100,
        ))], 1000);
        self::assertNull($this->owner->observation($component)->liveInstances);
        self::assertNull($this->owner->observation($component)->observedAt);
        $this->apply(false, [new ClusterDaemonNodeView('n1', true, new ClusterDaemonNodeSlot(
            'n1', new NodeDaemonPicture('n1', NodeRole::Master, 9999999999, $running), 101,
        ))], 2000);
        self::assertSame(1, $this->owner->observation($component)->expectedInstances);
        self::assertSame(1, $this->owner->observation($component)->liveInstances);
        self::assertSame(2000, $this->owner->observation($component)->observedAt);

        $server->stopPublic('probe');
        $worker->report(new WorkerAgentStoppedDTO('probe', 'probe'));
        self::assertFalse($manager->hasAgent('probe'));
        $absent = StatusComponentBootstrapTestSupport::roster($server, $manager);
        self::assertSame([], $absent->workers[0]->agents);
        $this->apply(false, [new ClusterDaemonNodeView('n1', true, new ClusterDaemonNodeSlot(
            'n1', new NodeDaemonPicture('n1', NodeRole::Master, 9999999999, $absent), 102,
        ))], 3000);
        self::assertSame(0, $this->owner->observation($component)->liveInstances);
        self::assertSame(3000, $this->owner->observation($component)->observedAt);
    }

    public function testPartialFramesCannotProveMembershipAndTheFirstWholeIsOnlyABaseline(): void
    {
        $component = $this->component(AgentScope::NODE);
        self::assertNull($this->owner->observation($component)->liveInstances);
        $node = $this->node('n1', true, []);
        $this->apply(false, [$node], 1000);
        self::assertNull($this->owner->observation($component)->observedAt);
        $this->apply(true, [$node], 2000);
        self::assertNull($this->owner->observation($component)->liveInstances);
        $this->apply(true, [$node], 3000);
        self::assertNull($this->owner->observation($component)->liveInstances, 'An unchanged lease snapshot proves no fresh report');
        $this->apply(false, [$this->node('n1', true, [], receivedAt: 99)], 4000);
        $observation = $this->owner->observation($component);
        self::assertSame(1, $observation->expectedInstances);
        self::assertSame(0, $observation->liveInstances, 'A reported empty roster is known absence');
        self::assertSame(4000, $observation->observedAt, 'Foreign time may move backward; observedAt uses local acceptance time');
    }

    public function testEveryOnlineNodeMustReportBeforeEitherScopeIsKnown(): void
    {
        $this->apply(true, [$this->node('n1'), $this->node('n2')], 1000);
        $this->apply(false, [$this->node('n1', receivedAt: 101)], 2000);
        foreach ([AgentScope::CLUSTER, AgentScope::NODE] as $scope) {
            self::assertNull($this->owner->observation($this->component($scope))->liveInstances);
        }
        $this->apply(false, [$this->node('n2', agents: [], receivedAt: 101)], 3000);
        self::assertSame(1, $this->owner->observation($this->component(AgentScope::CLUSTER))->liveInstances);
        $node = $this->owner->observation($this->component(AgentScope::NODE));
        self::assertSame(2, $node->expectedInstances);
        self::assertSame(1, $node->liveInstances, 'One occupied slot out of two is only partial recovery');
        $this->apply(false, [$this->node('n2', receivedAt: 102)], 4000);
        self::assertSame(2, $this->owner->observation($this->component(AgentScope::NODE))->liveInstances);
    }

    public function testMissingRosterAndMissingSlotRemainUnknownUntilAUsableReport(): void
    {
        $component = $this->component(AgentScope::NODE);
        $this->apply(true, [new ClusterDaemonNodeView('n1', true, null), $this->node('n2', agents: null)], 1000);
        $this->apply(false, [$this->node('n1', agents: [], receivedAt: 101), $this->node('n2', agents: null, receivedAt: 102)], 2000);
        self::assertNull($this->owner->observation($component)->liveInstances);
        $this->apply(false, [$this->node('n2', agents: [], receivedAt: 103)], 3000);
        self::assertSame(0, $this->owner->observation($component)->liveInstances);
        $this->apply(false, [$this->node('n2', agents: null, receivedAt: 104)], 4000);
        self::assertNull($this->owner->observation($component)->liveInstances, 'A restarted node with no roster loses usability');
    }

    public function testOfflineMembershipNeverCountsItsSavedProcessesAndDoesNotShrinkOnDisappearance(): void
    {
        $component = $this->component(AgentScope::NODE);
        $this->apply(true, [$this->node('n1', false), new ClusterDaemonNodeView('n2', false, null)], 1000);
        $observation = $this->owner->observation($component);
        self::assertSame(2, $observation->expectedInstances);
        self::assertSame(0, $observation->liveInstances);
        $this->apply(true, [], 2000);
        self::assertSame(2, $this->owner->observation($component)->expectedInstances);
        self::assertSame(0, $this->owner->observation($component)->liveInstances);
        $this->apply(false, [$this->node('n1', true)], 3000);
        self::assertNull($this->owner->observation($component)->liveInstances, 'Online alone does not refresh an old roster');
        $this->apply(false, [$this->node('n1', true, receivedAt: 101)], 4000);
        self::assertSame(1, $this->owner->observation($component)->liveInstances);
        self::assertSame(2, $this->owner->observation($component)->expectedInstances);
    }

    public function testClusterCopiesAndIndexedIdsDoNotFillAnotherNodeSlot(): void
    {
        $agents = ['probe', 'probe:1', 'probe:2'];
        $this->apply(true, [$this->node('n1', agents: $agents), $this->node('n2', agents: [])], 1000);
        $this->apply(false, [$this->node('n1', agents: $agents, receivedAt: 101), $this->node('n2', agents: [], receivedAt: 101)], 2000);
        self::assertSame(1, $this->owner->observation($this->component(AgentScope::NODE))->liveInstances);
        $this->apply(false, [$this->node('n2', receivedAt: 102)], 3000);
        $cluster = $this->owner->observation($this->component(AgentScope::CLUSTER));
        self::assertSame(1, $cluster->expectedInstances);
        self::assertSame(1, $cluster->liveInstances, 'Duplicate cluster copies still occupy only one expected slot');
    }

    public function testDuplicateCopiesOnOneNodeAreRefusedAtTheExistingRosterBoundary(): void
    {
        $agent = new DaemonAgentPicture('probe', 'node', 'node');
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage('repeats an agent id');
        new DaemonProcessRoster([
            new DaemonWorkerPicture(1, 'regular', 11, 4096, [$agent]),
            new DaemonWorkerPicture(2, 'regular', 12, 4096, [$agent]),
        ], null, 0);
    }

    public function testEmptyMembershipIsUnknownAndAReportedEmptyWorkerRosterIsKnownZero(): void
    {
        $component = $this->component(AgentScope::NODE);
        $this->apply(true, [], 1000);
        self::assertNull($this->owner->observation($component)->liveInstances);
        $empty = new NodeDaemonPicture('n1', NodeRole::Master, 9999999999, new DaemonProcessRoster([], null, 0));
        $this->apply(false, [new ClusterDaemonNodeView('n1', true, new ClusterDaemonNodeSlot('n1', $empty, 100))], 2000);
        self::assertNull($this->owner->observation($component)->liveInstances, 'A newly seen online member needs a fresh report');
        $this->apply(false, [new ClusterDaemonNodeView('n1', true, new ClusterDaemonNodeSlot('n1', $empty, 101))], 3000);
        self::assertSame(0, $this->owner->observation($component)->liveInstances);
        self::assertSame(3000, $this->owner->observation($component)->observedAt);
    }

    public function testOwnerRestartDiscardsTheOldFreshnessEvenForTheSameNodePicture(): void
    {
        $component = $this->component(AgentScope::NODE);
        $this->apply(true, [$this->node('n1')], 1000);
        $this->apply(false, [$this->node('n1', receivedAt: 101)], 2000);
        self::assertSame(1, $this->owner->observation($component)->liveInstances);
        $this->owner->onStart();
        self::assertNull($this->owner->observation($component)->liveInstances);
        $this->apply(true, [$this->node('n1', receivedAt: 101)], 3000);
        $this->apply(true, [$this->node('n1', receivedAt: 101)], 4000);
        self::assertNull($this->owner->observation($component)->liveInstances);
        $this->apply(false, [$this->node('n1', receivedAt: 102)], 5000);
        self::assertSame(1, $this->owner->observation($component)->liveInstances);
    }

    public function testRepeatsAndMetricsDoNotChangeTheSlotFactAndNoCountsArePublishedBeforeReconciliation(): void
    {
        $this->owner->tickAt($this->startedAt);
        $this->apply(true, [$this->node('n1')], 1000);
        $this->apply(false, [$this->node('n1', receivedAt: 101)], 2000);
        $component = $this->component(AgentScope::NODE);
        $before = $this->owner->observation($component);
        $this->apply(true, [$this->node('n1', receivedAt: 101)], 3000);
        self::assertEquals($before, $this->owner->observation($component));
        $this->apply(false, [$this->node('n1', receivedAt: 101, memoryBytes: 8192)], 4000);
        self::assertSame($before->liveInstances, $this->owner->observation($component)->liveInstances);
        self::assertSame($before->expectedInstances, $this->owner->observation($component)->expectedInstances);
        self::assertNull(Hilos::$rt->hilosStatusComponents['service']->liveInstances);
        self::assertNull(Hilos::$rt->hilosStatusComponents['service']->observedAt);
        $this->apply(false, [$this->node('n1')], 5000);
        self::assertNull($this->owner->observation($component)->liveInstances, 'A replay of the baseline cannot attest freshness');
    }

    public function testLeaseStartsWithoutViewersAndRetriesLostClaimOrSnapshotAfterInitializationEnds(): void
    {
        $this->assertWatch(1);
        $this->owner->tickAt($this->startedAt);
        $this->drain();
        $this->owner->tickAt($this->startedAt + 999);
        self::assertSame([], $this->drain());
        $this->owner->tickAt($this->startedAt + 1000);
        $claim = $this->assertWatch(1);
        $collector = new DaemonCollectorAgent();
        $collector->onStart();
        $this->carryWatch($collector, $claim);
        $this->drain(); // Lose the initial snapshot as well as the earlier claim.
        $this->apply(false, [$this->node('n1')], 1000);
        $this->owner->tickAt($this->startedAt + 2000);
        $this->carryWatch($collector, $this->assertWatch(1));
        $this->carrySnapshot();
        $this->owner->tickAt($this->startedAt + 31999);
        self::assertSame([], $this->drain());
        $this->owner->tickAt($this->startedAt + 32000);
        $this->assertWatch(1);
        self::assertCount(1, Hilos::$rt->hilosStatusComponents, 'Watch renew continues after the one-row cursor is exhausted');
    }

    public function testCollectorRestartIsRepairedByLeaseAndNormalStopReleasesInterest(): void
    {
        $collector = new DaemonCollectorAgent();
        $collector->onStart();
        $this->carryWatch($collector, $this->assertWatch(1));
        $this->carrySnapshot();
        $this->owner->tickAt($this->startedAt);
        $this->drain();
        $restarted = new DaemonCollectorAgent();
        $restarted->onStart();
        $restarted->applyNodePicture($this->node('n1')->slot->picture);
        $restarted->fanOutIfDue($this->startedAt / 1000 + 1.0);
        self::assertSame([], $this->drain());
        $this->owner->tickAt($this->startedAt + 30000);
        $this->carryWatch($restarted, $this->assertWatch(1));
        $this->carrySnapshot();
        self::assertSame(0, $this->owner->observation($this->component(AgentScope::NODE))->liveInstances,
            'Collector projects a remembered reporter absent from membership as offline');
        $this->owner->onStop();
        $this->carryWatch($restarted, $this->assertWatch(0));
        $restarted->applyNodePicture($this->node('n2')->slot->picture);
        $restarted->fanOutIfDue($this->startedAt / 1000 + 31.0);
        self::assertSame([], $this->drain());
    }

    /** @return iterable<string, array{string}> Every protected-mode phase holds observations */
    public static function freezes(): iterable
    {
        yield 'activating' => [ProtectedModeRuntime::PHASE_ACTIVATING];
        yield 'active' => [ProtectedModeRuntime::PHASE_ACTIVE];
        yield 'verifying' => [ProtectedModeRuntime::PHASE_VERIFYING];
        yield 'deactivating' => [ProtectedModeRuntime::PHASE_DEACTIVATING];
    }

    /** @param string $phase Freeze phase whose stops must not turn into outage facts */
    #[DataProvider('freezes')]
    public function testFreezeDiscardsBuffersAndLiftNeedsAWholeBaselineAndNewReport(string $phase): void
    {
        $component = $this->component(AgentScope::NODE);
        $this->apply(true, [$this->node('n1')], 1000);
        $this->apply(false, [$this->node('n1', receivedAt: 101)], 2000);
        self::assertSame(1, $this->owner->observation($component)->liveInstances);
        $this->runtime->phase($phase);
        $this->drain();
        $this->apply(true, [$this->node('n1', agents: [], receivedAt: 102)], 3000);
        $this->owner->tickAt($this->startedAt + 30000);
        self::assertNull($this->owner->observation($component)->liveInstances);
        self::assertCount(0, Hilos::$rt->hilosStatusComponents, 'No initialization writes while held');
        $this->owner->onStop();
        self::assertSame([], $this->drain(), 'Freeze permits neither renew nor stop delivery');
        $this->runtime->phase(ProtectedModeRuntime::PHASE_INACTIVE);
        $this->owner->tickAt($this->startedAt + 31000);
        $this->assertWatch(1, true);
        $this->apply(false, [$this->node('n1', receivedAt: 103)], 4000);
        self::assertNull($this->owner->observation($component)->liveInstances);
        $this->apply(true, [$this->node('n1', receivedAt: 101)], 5000);
        $this->apply(true, [$this->node('n1', receivedAt: 101)], 6000);
        self::assertNull($this->owner->observation($component)->liveInstances);
        $this->apply(false, [$this->node('n1', receivedAt: 104)], 7000);
        self::assertSame(1, $this->owner->observation($component)->liveInstances);
    }

    public function testUnknownRuntimeHoldsDeliveryAndDiscardsPreviouslyUsableData(): void
    {
        $this->drain();
        Hilos::$rt = null;
        $this->owner->tickAt($this->startedAt + 30000);
        self::assertNull($this->owner->observation($this->component(AgentScope::NODE))->liveInstances);
        $this->owner->onStop();
        self::assertSame([], $this->drain());
    }

    public function testAnotherConsumerResponseIsNotOwnedByStatus(): void
    {
        $this->expectException(AgentUnknownSignalException::class);
        $this->owner->onSignalAgent(new AgentSignalData(data: new DaemonClusterPicturePortionSignalData(true, [])),
            'agent/hilos_daemon_collector', HilosSignalConstants::DAEMON_CLUSTER_PICTURE_PORTION);
    }

    private function component(AgentScope $scope): StatusComponentDefinition
    {
        return new StatusComponentDefinition('service', 'Service', null, 'probe', $scope, AgentPlacement::POLICY);
    }

    /** @param ?list<string> $agents Started ids, null for no process report */
    private function node(
        string $id,
        bool $online = true,
        ?array $agents = ['probe'],
        int $receivedAt = 100,
        int $memoryBytes = 4096,
    ): ClusterDaemonNodeView {
        $roster = $agents === null ? null : new DaemonProcessRoster([
            new DaemonWorkerPicture(1, 'regular', null, $memoryBytes,
                array_map(static fn (string $agent): DaemonAgentPicture => new DaemonAgentPicture($agent, 'node', 'node'), $agents)),
        ], null, 0);
        return new ClusterDaemonNodeView($id, $online,
            new ClusterDaemonNodeSlot($id, new NodeDaemonPicture($id, NodeRole::Master, 9999999999, $roster), $receivedAt));
    }

    /** @param list<ClusterDaemonNodeView> $nodes Whole node slots */
    private function apply(bool $snapshot, array $nodes, int $nowMs): void
    {
        $wire = new DaemonClusterPicturePortionSignalData($snapshot, $nodes);
        $this->owner->applyPicturePortion(DaemonClusterPicturePortionSignalData::fromArray($wire->toArray()), $nowMs);
    }

    private function carryWatch(DaemonCollectorAgent $collector, DaemonPictureWatchSignalData $claim): void
    {
        $collector->onSignalAgent(new AgentSignalData(data: DaemonPictureWatchSignalData::fromArray($claim->toArray())),
            'agent/hilos_status', HilosSignalConstants::STATUS_DAEMON_PICTURE_WATCH);
    }

    private function carrySnapshot(): void
    {
        $frames = $this->drain();
        self::assertCount(1, $frames);
        self::assertSame(HilosSignalConstants::STATUS_DAEMON_PICTURE_PORTION, $frames[0]->signalName->getName());
        $wire = $frames[0]->data->data;
        self::assertInstanceOf(DaemonClusterPicturePortionSignalData::class, $wire);
        self::assertTrue($wire->snapshot);
        $this->owner->onSignalAgent(new AgentSignalData(data: DaemonClusterPicturePortionSignalData::fromArray($wire->toArray())),
            'agent/hilos_daemon_collector', HilosSignalConstants::STATUS_DAEMON_PICTURE_PORTION);
    }

    private function assertWatch(int $viewers, bool $withRuntimeFrames = false): DaemonPictureWatchSignalData
    {
        $frames = $this->drain();
        if ($withRuntimeFrames) {
            $frames = array_values(array_filter($frames, static fn (SignalDTO $frame): bool =>
                $frame->signalName->getName() === HilosSignalConstants::STATUS_DAEMON_PICTURE_WATCH));
        }
        self::assertCount(1, $frames);
        self::assertSame(HilosSignalConstants::STATUS_DAEMON_PICTURE_WATCH, $frames[0]->signalName->getName());
        self::assertInstanceOf(DaemonPictureWatchSignalData::class, $frames[0]->data->data);
        self::assertSame($viewers, $frames[0]->data->data->viewers);
        return $frames[0]->data->data;
    }

    /** @return list<SignalDTO> Queued frames */
    private function drain(): array
    {
        $frames = [];
        while (($frame = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $frames[] = $frame;
        }
        return $frames;
    }
}

abstract class StatusObservationHilos extends Hilos
{
    public const array AGENTS = [HilosAgentType::HILOS_INDEX => []];
}

/** Permanent leader component deliberately absent from SignalRouter's project bootstrap list. */
abstract class StatusObservationBootstrapHilos extends StatusObservationHilos
{
    public const array AGENTS = [
        ...parent::AGENTS,
        'probe' => [AgentRegistryKey::SCOPE => AgentScope::CLUSTER],
    ];

    protected const array FEATURES = [HilosFeature::STATUS];
    protected const array STATUS_COMPONENTS = [
        'service' => ['name' => 'Service', 'description' => null, 'agentType' => 'probe'],
    ];
}

/** Lease-only cases stay unknown; the current-incidents source is a known empty manual set. */
final class StatusObservationDbContext extends HilosDbContext
{
    /** Mounts the production read facade without a SQL-backed store. */
    public function configure(): void
    {
        $this->_objectCollections[self::statusIncidents] = ObjectStatusIncidents::initEmpty();
        $this->setRepresent(self::statusIncidents, StatusIncidents::class);
    }
}

final class StatusObservationRuntime extends RtContext
{
    public function configure(): void
    {
    }

    /** @param string $phase Isolated runtime projection, without a daemon or destructive operation */
    public function phase(string $phase): void
    {
        $freeze = ProtectedModeRuntime::create();
        $freeze->phase = $phase;
        $this->mountFeatureItem(ProtectedModeRuntime::RT_ITEM, $freeze);
    }
}
