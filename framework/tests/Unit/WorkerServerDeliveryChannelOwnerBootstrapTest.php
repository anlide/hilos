<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Leadership;
use Hilos\Cluster\PendingLeadership;
use Hilos\Cluster\StandaloneLeadership;
use Hilos\Cluster\Peer\DTO\PeerPlacedAgentEntry;
use Hilos\Cluster\Peer\DTO\PeerPlacementReportDTO;
use Hilos\Cluster\Placement\ClusterPlacement;
use Hilos\Cluster\Placement\PlacementState;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Agent\Config\AgentSignalConfigKey;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Daemon\ContainedFailure;
use Hilos\Core\Daemon\ContainedFailureSink;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Daemon\Master\MasterFailureUnit;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Topology\Exception\InvalidTopologyException;
use Hilos\Hilos;
use Hilos\Notification\Delivery\AbstractDeliveryChannel;
use Hilos\Notification\Delivery\DeliveryChannelRegistry;
use Hilos\Notification\Delivery\DTO\NotificationDeliverSignalData;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Worker\DTO\WorkerAgentStartedDTO;
use Hilos\Tests\Unit\Cluster\Placement\FakePlacementMesh;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

require_once __DIR__ . '/StatusComponentBootstrapTestSupport.php';
require_once __DIR__ . '/Cluster/Placement/ClusterPlacementTest.php';

/** Delivery owners start through real worker frames without a delivery or browser addressing them. */
final class WorkerServerDeliveryChannelOwnerBootstrapTest extends TestCase
{
    private const int WORKER_INDEX = 17;

    /** @var class-string<Hilos> Active facade to restore */
    private string $boundAppClass;

    /** @var array{?SignalRouter, ?RtContext, ?ClusterContext} Process globals replaced by each fixture */
    private array $facadeGlobals;

    /** Installs only declaration metadata and an in-memory worker link. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->boundAppClass = Hilos::appClass();
        $this->facadeGlobals = [Hilos::$sr, Hilos::$rt, Hilos::$cluster];
        $this->bindApp(DeliveryBootstrapTestHilos::class);
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = null;
        Hilos::$cluster = null;
        DeliveryBootstrapRegistry::$channels = [
            'pool' => new DeliveryBootstrapChannel(pooled: true),
            'singleton' => new DeliveryBootstrapChannel(pooled: false),
        ];
    }

    /** Restores the facade boundaries and discards the current case's catalog. */
    protected function tearDown(): void
    {
        DeliveryBootstrapRegistry::$channels = [];
        $this->bindApp($this->boundAppClass);
        [Hilos::$sr, Hilos::$rt, Hilos::$cluster] = $this->facadeGlobals;
        parent::tearDown();
    }

    /** @return iterable<string, array{?Leadership}> Standalone and explicit leader contexts */
    public static function leaderContexts(): iterable
    {
        yield 'standalone' => [null];
        yield 'leader' => [new StandaloneLeadership()];
    }

    /** @param ?Leadership $leadership Explicit leader seam, or the standalone null boundary */
    #[DataProvider('leaderContexts')]
    public function testLeaderStartsAllChannelIndexesWithoutAnAddressedFrame(?Leadership $leadership): void
    {
        if ($leadership !== null) {
            $this->installLeadership($leadership);
        }
        [$server, $manager, $worker] = $this->buildServer();

        $server->onBecameSingletonHost();

        $this->assertSame(['delivery_pool:1', 'delivery_pool:2', 'delivery_pool:3', 'delivery_singleton'], $worker->startIds());
        $this->assertCount(4, $worker->frames(), 'Only agent_start frames are needed to raise the whole catalog.');
        $this->assertSame($worker->startIds(), array_keys($manager->getAgents()));
        foreach ($worker->startIds() as $id) {
            $this->assertSame(self::WORKER_INDEX, $manager->getAgentWorkerId($id));
            $this->assertFalse($manager->isAgentStarted($id));
        }
        $signal = Hilos::$sr->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertSame([], Hilos::$sr->getDestinations($signal), 'The project bootstrap list is empty.');
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    /** @return iterable<string, array{string}> Both leader lifecycle requests for delivery owners */
    public static function deliveryBootstrapHooks(): iterable
    {
        yield 'promotion' => ['onBecameSingletonHost'];
        yield 'protected-mode lift' => ['liftPublic'];
    }

    /** @param string $hook Leader lifecycle request repeated after adopting the former leader's owners */
    #[DataProvider('deliveryBootstrapHooks')]
    public function testNewLeaderKeepsDeliveryOwnersAlreadyRunningOnTheFormerLeader(string $hook): void
    {
        [$server, $manager, $worker] = $this->buildServer();
        [$placement, $mesh] = $this->buildLeaderPlacement($server);
        $placement->onPlacementReport('node-a', new PeerPlacementReportDTO([
            new PeerPlacedAgentEntry(DeliveryBootstrapPoolAgent::AGENT_TYPE, '1'),
            new PeerPlacedAgentEntry(DeliveryBootstrapPoolAgent::AGENT_TYPE, '2'),
            new PeerPlacedAgentEntry(DeliveryBootstrapPoolAgent::AGENT_TYPE, '3'),
            new PeerPlacedAgentEntry(DeliveryBootstrapSingletonAgent::AGENT_TYPE, null),
        ]));
        $placement->tick(1000.0);
        $records = $placement->registry()->all();
        $revision = $placement->registry()->revision();
        $mesh->sent = [];

        $server->$hook();
        $server->$hook();

        $this->assertSame([], $worker->frames(), 'A live remote owner must never get a second local copy.');
        $this->assertSame([], $manager->getAgents());
        $this->assertSame([], $mesh->sent, 'Already started owners need no new placement frames.');
        $this->assertCount(4, $records);
        $this->assertSame($records, $placement->registry()->all());
        $this->assertSame($revision, $placement->registry()->revision());
        foreach ($records as $record) {
            $this->assertSame('node-a', $record->nodeId);
            $this->assertSame(PlacementState::Started, $record->state);
        }
    }

    /** @param string $hook Leader lifecycle request made before the former leader reports its live owners */
    #[DataProvider('deliveryBootstrapHooks')]
    public function testDeliveryDemandWaitsForRebuildThenStartsOnlyMissingOwnersWithoutAnotherHook(string $hook): void
    {
        [$server, $manager, $worker] = $this->buildServer();
        [$placement] = $this->buildLeaderPlacement($server);

        $server->$hook();
        $server->$hook();

        $this->assertSame([], $worker->frames(), 'Bootstrap must wait until every old host has reported.');
        $this->assertSame([], $manager->getAgents());
        $this->assertSame([], $placement->registry()->all());
        $placement->onPlacementReport('node-a', new PeerPlacementReportDTO([
            new PeerPlacedAgentEntry(DeliveryBootstrapPoolAgent::AGENT_TYPE, '1'),
        ]));
        $this->assertSame([], $worker->frames(), 'The report alone does not settle snapshot inheritance.');

        $placement->tick(1000.1);

        $this->assertSame(['delivery_pool:2', 'delivery_pool:3', 'delivery_singleton'], $worker->startIds());
        $this->assertSame($worker->startIds(), array_keys($manager->getAgents()));
        $this->assertCount(4, $placement->registry()->all(), 'Held demand survives without another lifecycle request.');
        $this->assertSame('node-a', $placement->registry()->get('delivery_pool:1')?->nodeId);
        $this->assertSame(PlacementState::Started, $placement->registry()->get('delivery_pool:1')?->state);
        foreach ($worker->startIds() as $id) {
            $this->assertSame('node-b', $placement->registry()->get($id)?->nodeId);
            $this->assertSame(self::WORKER_INDEX, $manager->getAgentWorkerId($id));
        }

        $server->$hook();

        $this->assertSame(['delivery_pool:2', 'delivery_pool:3', 'delivery_singleton'], $worker->startIds());
    }

    /** NODE replicas and LEADER owners retain their local start even while cluster placement rebuilds. */
    public function testNonPolicyClusterOwnersKeepTheOrdinaryLocalStart(): void
    {
        $this->bindApp(DeliveryBootstrapLocalHilos::class);
        [$server, $manager, $worker] = $this->buildServer();
        [$placement] = $this->buildLeaderPlacement($server);

        $server->onBecameSingletonHost();

        $this->assertSame(['delivery_pool:1', 'delivery_pool:2', 'delivery_pool:3', 'delivery_singleton'], $worker->startIds());
        $this->assertSame($worker->startIds(), array_keys($manager->getAgents()));
        $this->assertSame([], $placement->registry()->all());
    }

    /** Both pending and acknowledged owners survive repeat hooks and overlapping manual starts. */
    public function testRepeatedBootstrapKeepsTheSamePendingAndRunningOwners(): void
    {
        [$server, $manager, $worker] = $this->buildServer();
        $server->onBecameSingletonHost();
        $firstOwners = $manager->getAgents();
        $server->onBecameSingletonHost();
        $server->ensureAgentUp(DeliveryBootstrapPoolAgent::AGENT_TYPE, '2');
        foreach ($firstOwners as $id => $owner) {
            $worker->report(new WorkerAgentStartedDTO($id, $owner->getType(), $owner->getIndex()));
        }

        $server->onBecameSingletonHost();

        $this->assertSame(['delivery_pool:1', 'delivery_pool:2', 'delivery_pool:3', 'delivery_singleton'], $worker->startIds());
        $this->assertSame($firstOwners, $manager->getAgents());
        foreach (array_keys($firstOwners) as $id) {
            $this->assertTrue($manager->isAgentStarted($id));
        }
    }

    /** A follower must return before even trying to read an invalid registry, including on lift. */
    public function testFollowerNeverReadsOrStartsChannelOwners(): void
    {
        $this->bindApp(DeliveryBootstrapUnreadableHilos::class);
        $this->installLeadership(new PendingLeadership());
        [$server, $manager, $worker] = $this->buildServer();

        $server->onBecameSingletonHost();
        $server->liftPublic();

        $this->assertSame([], $worker->frames());
        $this->assertSame([], $manager->requestedIds);
    }

    /** The disabled feature must not ask the channel registry to enumerate anything. */
    public function testDisabledDeliveryNeverReadsTheChannelRegistry(): void
    {
        $this->bindApp(DeliveryBootstrapDisabledHilos::class);
        [$server, $manager, $worker] = $this->buildServer();

        $server->onBecameSingletonHost();
        $server->liftPublic();

        $this->assertSame([], $worker->frames());
        $this->assertSame([], $manager->getAgents());
    }

    /** A broken pool description is reported by channel and does not strand the next channel. */
    public function testChannelDescriptionFailureIsContainedBeforeStartingTheNextChannel(): void
    {
        DeliveryBootstrapRegistry::$channels['pool'] = new DeliveryBootstrapChannel(pooled: true, width: 0);
        [$server, $manager, $worker] = $this->buildServer();
        $sink = new DeliveryBootstrapFailureSink();
        $server->setContainedFailureSink($sink);

        $server->onBecameSingletonHost();

        $this->assertSame(['delivery_singleton'], $worker->startIds());
        $this->assertSame(['delivery_singleton'], $manager->requestedIds);
        $this->assertCount(1, $sink->reported);
        $this->assertSame(MasterFailureUnit::AGENT_START, $sink->reported[0]->unit);
        $this->assertSame('delivery channel pool', $sink->reported[0]->address);
        $this->assertInstanceOf(InvalidTopologyException::class, $sink->reported[0]->failure);
    }

    /** An index's factory failure leaves both later indexes and later channels reachable. */
    public function testIndexStartFailureIsContainedWithoutStrandingLaterOwners(): void
    {
        [$server, $manager, $worker] = $this->buildServer();
        $manager->failingId = 'delivery_pool:2';
        $sink = new DeliveryBootstrapFailureSink();
        $server->setContainedFailureSink($sink);

        $server->onBecameSingletonHost();

        $this->assertSame(['delivery_pool:1', 'delivery_pool:3', 'delivery_singleton'], $worker->startIds());
        $this->assertSame(['delivery_pool:1', 'delivery_pool:2', 'delivery_pool:3', 'delivery_singleton'], $manager->requestedIds);
        $this->assertFalse($manager->hasAgent('delivery_pool:2'));
        $this->assertCount(1, $sink->reported);
        $this->assertSame(MasterFailureUnit::AGENT_START, $sink->reported[0]->unit);
        $this->assertSame('delivery_pool:2', $sink->reported[0]->address);
        $this->assertInstanceOf(AgentDaemonCreationFailedException::class, $sink->reported[0]->failure);
    }

    /** A start refused under freeze has no remembered roster; either opening phase still raises it. */
    public function testProtectedModeLiftStartsOwnersAbsentFromTheRememberedRoster(): void
    {
        [$server, $manager, $worker] = $this->buildServer();
        $this->freeze(StateProtectedModeRuntime::PHASE_ACTIVE);
        $server->onBecameSingletonHost();
        $this->assertSame([], $manager->getAgents());
        $this->assertSame([], $manager->requestedIds);
        $this->assertSame([], $server->getProtectedModeStoppedAgents());

        foreach ([StateProtectedModeRuntime::PHASE_VERIFYING, StateProtectedModeRuntime::PHASE_INACTIVE] as $phase) {
            $this->freeze($phase);
            $server->resumeAgentsForProtectedMode();
            $server->rosterStepPublic();
            $server->liftPublic();
        }

        $this->assertSame(['delivery_pool:1', 'delivery_pool:2', 'delivery_pool:3', 'delivery_singleton'], $worker->startIds());
        $this->assertSame($worker->startIds(), array_keys($manager->getAgents()));
    }

    /** The policy, rather than a leadership term, owns both the pool and singleton placement. */
    public function testLosingLeadershipDoesNotStopPolicyPlacedDeliveryOwners(): void
    {
        [$server, $manager, $worker] = $this->buildServer();
        $this->installLeadership(new StandaloneLeadership());
        $server->onBecameSingletonHost();
        $owners = $manager->getAgents();
        $frames = $worker->frames();

        $this->installLeadership(new PendingLeadership());
        $server->onLostSingletonHost();
        $server->onBecameSingletonHost();

        $this->assertSame($owners, $manager->getAgents());
        $this->assertEquals($frames, $worker->frames(), 'Leadership loss sends no stop or second start to POLICY owners.');
    }

    /** Real loss rearming repeats the once-per-term pass onto the replacement worker. */
    public function testWorkerLossRearmsBootstrapAndStartsOwnersOnTheReplacementWorker(): void
    {
        $daemon = new DeliveryBootstrapDaemonManager();
        [$server, $manager, $worker] = $this->buildServer($daemon->getAgentManagerDaemon());
        $daemon->registerServer($server);
        new ReflectionProperty(DaemonManager::class, 'workersReady')->setValue($daemon, true);
        $ensure = new ReflectionMethod(DaemonManager::class, 'ensureSingletonsStarted');
        $ensure->invoke($daemon);
        $ensure->invoke($daemon);
        $this->assertSame(['delivery_pool:1', 'delivery_pool:2', 'delivery_pool:3', 'delivery_singleton'], $worker->startIds());
        $server->disconnectWorker();
        $this->assertSame($worker->startIds(), $manager->forgetAgentsOfWorker(self::WORKER_INDEX, false));
        $this->assertSame([], $manager->getAgents());
        $replacement = $server->addWorker(self::WORKER_INDEX + 1);

        $ensure->invoke($daemon);
        $ensure->invoke($daemon);

        $this->assertSame($worker->startIds(), $replacement->startIds());
        foreach ($replacement->startIds() as $id) {
            $this->assertSame(self::WORKER_INDEX + 1, $manager->getAgentWorkerId($id));
        }
    }

    /**
     * Reuses the Status lifecycle fixture's real worker buffering and public hook adapters.
     *
     * @param ?DeliveryBootstrapAgentManager $manager Optional daemon-owned roster
     * @return array{StatusBootstrapTestWorkerServer, DeliveryBootstrapAgentManager, StatusBootstrapTestWorkerClient} Linked fixture
     */
    private function buildServer(?DeliveryBootstrapAgentManager $manager = null): array
    {
        $manager ??= new DeliveryBootstrapAgentManager();
        // Worker environment and log-directory setup are outside this in-memory lifecycle case.
        $server = new ReflectionClass(StatusBootstrapTestWorkerServer::class)->newInstanceWithoutConstructor();
        new ReflectionProperty(WorkerServer::class, 'agentManager')->setValue($server, $manager);

        return [$server, $manager, $server->addWorker(self::WORKER_INDEX)];
    }

    /**
     * @param StatusBootstrapTestWorkerServer $server Real local placement executor and worker start gates
     * @return array{ClusterPlacement, FakePlacementMesh} New leader awaiting the former leader's report
     */
    private function buildLeaderPlacement(StatusBootstrapTestWorkerServer $server): array
    {
        $this->installLeadership(new StandaloneLeadership());
        $mesh = new FakePlacementMesh(
            capabilities: ['node-b' => ['slots=10'], 'node-a' => ['slots=1']],
            linked: ['node-a'],
            online: ['node-b', 'node-a'],
        );
        $placement = new ClusterPlacement('node-b', $mesh, $server);
        Hilos::$cluster->registerPlacement($placement);
        $placement->onBecameLeader(1000.0);

        return [$placement, $mesh];
    }

    /** @param class-string<Hilos> $appClass Fixture facade */
    private function bindApp(string $appClass): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $appClass);
    }

    /** @param Leadership $leadership Local leadership verdict */
    private function installLeadership(Leadership $leadership): void
    {
        Hilos::$cluster = new ClusterContext();
        Hilos::$cluster->registerLeadership($leadership);
    }

    /** @param string $phase Freeze phase received through the runtime snapshot boundary */
    private function freeze(string $phase): void
    {
        Hilos::$rt = new DeliveryBootstrapRtContext();
        Hilos::$rt->mountFeatureItem(StateProtectedModeRuntime::RT_ITEM, StateProtectedModeRuntime::fromRow([
            StateProtectedModeRuntime::phase => $phase,
            StateProtectedModeRuntime::initiatorAgentType => 'restorer',
            StateProtectedModeRuntime::initiatorAgentIndex => null,
            StateProtectedModeRuntime::passHashes => [],
            StateProtectedModeRuntime::admittedSessionTokenHashes => [],
            StateProtectedModeRuntime::circleSessionTokenHashes => [],
            StateProtectedModeRuntime::circleNamedCount => 0,
            StateProtectedModeRuntime::enteredFromBrowser => false,
        ]));
    }
}

/** Keeps production ensure-once and worker-loss rearming with the indexed fixture factory. */
final class DeliveryBootstrapDaemonManager extends DaemonManager
{
    /** @return SignalRouter Router with no project bootstrap targets */
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    /** @return DeliveryBootstrapAgentManager Real roster with controllable factory failure */
    protected function createAgentManagerDaemon(): DeliveryBootstrapAgentManager
    {
        return new DeliveryBootstrapAgentManager();
    }
}

/** Retains real roster operations and can refuse one specific pool index. */
final class DeliveryBootstrapAgentManager extends AgentManagerDaemon
{
    /** @var list<string> Requested owner identities in factory order */
    public array $requestedIds = [];

    /** @var ?string Single identity whose factory fails */
    public ?string $failingId = null;

    /**
     * @param string $agentType Requested owner type
     * @param ?string $agentIndex Requested owner index
     * @return AgentDaemonInterface Indexed or singleton regular-worker proxy
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        $id = $this->buildAgentId($agentType, $agentIndex);
        $this->requestedIds[] = $id;
        if ($id === $this->failingId) {
            throw new AgentDaemonCreationFailedException($agentType, $agentIndex);
        }

        return new DeliveryBootstrapAgentDaemon($agentType, $agentIndex);
    }
}

/** Regular-worker proxy whose production identity retains the requested pool index. */
final class DeliveryBootstrapAgentDaemon extends AbstractAgentDaemon
{
    /**
     * @param string $type Declared owner type
     * @param ?string $index Pool index, or null for a singleton
     */
    public function __construct(private readonly string $type, ?string $index)
    {
        $this->agentIndex = $index;
    }

    /** @return string Declared owner type */
    public function getType(): string
    {
        return $this->type;
    }

    /** @return bool False so the real selection uses the regular fixture worker */
    public function requiresMonopolisticProcess(): bool
    {
        return false;
    }
}

/** Records the production failure cards in report order. */
final class DeliveryBootstrapFailureSink implements ContainedFailureSink
{
    /** @var list<ContainedFailure> Reported channel and index failures */
    public array $reported = [];

    /** @param ContainedFailure $failure Contained start failure */
    public function reportContainedFailure(ContainedFailure $failure): void
    {
        $this->reported[] = $failure;
    }
}

/** Supplies only the framework's unconditional protected-mode row. */
final class DeliveryBootstrapRtContext extends RtContext
{
    /** No project runtime collections are used by these cases. */
    public function configure(): void
    {
    }
}

abstract class DeliveryBootstrapTestHilos extends Hilos
{
    public const array AGENTS = [
        DeliveryBootstrapPoolAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => DeliveryBootstrapPoolAgent::class,
            AgentRegistryKey::INDEXED => true,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        DeliveryBootstrapSingletonAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => DeliveryBootstrapSingletonAgent::class,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
    ];

    protected const array FEATURES = [HilosFeature::NOTIFICATION_DELIVERY];

    protected const string NOTIFICATION_CHANNEL_REGISTRY = DeliveryBootstrapRegistry::class;
}

abstract class DeliveryBootstrapUnreadableHilos extends DeliveryBootstrapTestHilos
{
    protected const string NOTIFICATION_CHANNEL_REGISTRY = DeliveryBootstrapUnreadableRegistry::class;
}

abstract class DeliveryBootstrapLocalHilos extends DeliveryBootstrapTestHilos
{
    public const array AGENTS = [
        DeliveryBootstrapPoolAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => DeliveryBootstrapPoolAgent::class,
            AgentRegistryKey::INDEXED => true,
            AgentRegistryKey::SCOPE => AgentScope::NODE,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        DeliveryBootstrapSingletonAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => DeliveryBootstrapSingletonAgent::class,
            AgentRegistryKey::PLACEMENT => AgentPlacement::LEADER,
        ],
    ];
}

abstract class DeliveryBootstrapDisabledHilos extends DeliveryBootstrapUnreadableHilos
{
    protected const array FEATURES = [];
}

/** Descriptors belong to one test case and are cleared on teardown. */
final class DeliveryBootstrapRegistry extends DeliveryChannelRegistry
{
    /** @var array<string, AbstractDeliveryChannel> Current case's catalog */
    public static array $channels = [];

    /** @return array<string, AbstractDeliveryChannel> Current case's catalog */
    protected static function channels(): array
    {
        return self::$channels;
    }
}

/** Fails if a follower or a disabled feature asks for the catalog. */
final class DeliveryBootstrapUnreadableRegistry extends DeliveryChannelRegistry
{
    /** @return array Never returns a catalog */
    protected static function channels(): array
    {
        TestCase::fail('This lifecycle gate must return before reading the channel registry.');
    }
}

/** Only constant metadata may be read during the master bootstrap. */
final class DeliveryBootstrapChannel extends AbstractDeliveryChannel
{
    /**
     * @param bool $pooled Whether this descriptor declares the pool or singleton
     * @param int $width Pool width to enumerate
     */
    public function __construct(private readonly bool $pooled, private readonly int $width = 3)
    {
    }

    /** @return string Channel registry key */
    public function name(): string
    {
        return $this->pooled ? 'pool' : 'singleton';
    }

    /** @return string Signal matching the declared owner */
    public function deliverSignalName(): string
    {
        return $this->pooled ? DeliveryBootstrapPoolAgent::SIGNAL : DeliveryBootstrapSingletonAgent::SIGNAL;
    }

    /** @return bool Whether this channel uses indexed owners */
    public function isPooled(): bool
    {
        return $this->pooled;
    }

    /** @return int Constant pool width without settings or transport reads */
    public function poolSize(): int
    {
        if (!$this->pooled) {
            TestCase::fail('A singleton must not read a pool width.');
        }

        return $this->width;
    }

    /**
     * @param int $userId Recipient whose address must remain unread
     * @return ?string Never resolves an address during bootstrap
     */
    public function resolveAddress(int $userId): ?string
    {
        TestCase::fail('Master bootstrap must not read a recipient address.');
    }

    /** @return string Never resolves settings during bootstrap */
    public function enabledSettingKey(): string
    {
        TestCase::fail('Master bootstrap must not read channel enablement.');
    }

    /** @return array Never reads transport configuration during bootstrap */
    public function configFields(): array
    {
        TestCase::fail('Master bootstrap must not read transport configuration.');
    }
}

class DeliveryBootstrapSingletonAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'delivery_singleton';
    public const string SIGNAL = 'bootstrap_singleton_deliver';
    public const array AGENT_SIGNALS = [self::SIGNAL];

    /** The declaration-only fixture holds no resources. */
    public function onStop(): void
    {
    }
}

final class DeliveryBootstrapPoolAgent extends DeliveryBootstrapSingletonAgent
{
    public const string AGENT_TYPE = 'delivery_pool';
    public const string SIGNAL = 'bootstrap_pool_deliver';
    public const array AGENT_SIGNALS = [
        self::SIGNAL => [AgentSignalConfigKey::INDEX_FIELD => NotificationDeliverSignalData::shardKey],
    ];
}
