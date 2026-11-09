<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Hilos\API\Router\HttpRouter;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Leadership;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Placement\ClusterPlacement;
use Hilos\Cluster\Placement\PlacementExecutor;
use Hilos\Cluster\Placement\PlacementMesh;
use Hilos\Cluster\Placement\PlacementRecord;
use Hilos\Cluster\Placement\PlacementState;
use Hilos\Cluster\StandaloneLeadership;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandChannelWindows;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Agent\AgentId;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Daemon\DaemonProcessRosterBuilder;
use Hilos\Core\Daemon\ProcessMetricsReader;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\EventLoop\EventLoopTimer;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\DaemonSection\DaemonWorkerPicture;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Runtime\RtSnapshot;
use Hilos\Runtime\RtSyncApplicator;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\Server\CommandServer;
use Hilos\Socket\Server\HttpServer;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentStartedDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Logger;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Socket;
use WeakReference;

/** Native continuations and stable absolute deadlines, with business work confined to the report phase. */
final class DaemonManagerProcessRosterNativeTest extends TestCase
{
    private EventLoop $loop;
    private RosterNativeManager $manager;
    private RosterNativeWorkerServer $server;
    private RosterNativeCommandServer $commands;
    private AgentDaemonInterface $agent;
    private ?ClusterContext $previousCluster;
    private ?RtContext $previousRuntime;
    private ?SignalRouter $previousRouter;
    private ?EnvAccessor $previousEnv;
    private string $previousApp;
    private string|false $previousAppEnv;
    private string|false $previousEnabled;
    private string $logFile;
    /** @var list<Socket> Real readable I/O, without waiting for time to pass */
    private array $sockets = [];

    public function testThousandAgentWholeFrameAndAllAvailableForceBarrierContinueWithoutInput(): void
    {
        $this->agents(1000);
        $this->ask('1234567890123456');
        $this->ask('second');
        $this->invoke('reconcileProcessRosterSources');
        $this->loop->tick();
        self::assertSame([], $this->server->delivered, 'A native cue cannot capture or send a roster');
        self::assertSame(0, $this->server->rosterReads);
        $this->roster();
        self::assertSame([], $this->server->delivered, 'The first hundred real visits are only a prefix');
        self::assertTrue($this->loop->hasReadyWork());
        $passes = $this->finishRoster();
        self::assertGreaterThan(10, $passes);
        self::assertSame([HilosSignalConstants::DAEMON_MASTER_PROCESS_ROSTER], $this->names());
        $roster = $this->server->delivered[0]->signal->data->data->roster;
        self::assertCount(1001, $roster->workers[0]->agents);
        self::assertSame(HilosAgentType::HILOS_DAEMON_NODE, $roster->workers[0]->agents[0]->id);
        self::assertSame('replica:0000', $roster->workers[0]->agents[1]->id);
        self::assertSame('replica:0999', $roster->workers[0]->agents[1000]->id);
        self::assertSame([], $roster->unplacedAgentIds);
        self::assertSame(1, $this->server->rosterReads);
        self::assertGreaterThan(1_700_000_000, $this->get('nodeReportRosterCompletedAt'));
        self::assertLessThan(microtime(true) + 1, $this->get('nodeReportRosterCompletedAt'));
        $this->invoke('dispatchCompletedNodeReport');
        self::assertCount(1, $this->server->delivered, 'Other available forced frames are still owed');
        $this->otherFrames();
        self::assertSame([
            HilosSignalConstants::DAEMON_MASTER_PROCESS_ROSTER,
            HilosSignalConstants::DAEMON_MASTER_CRON,
            HilosSignalConstants::DAEMON_MASTER_STANDING,
            HilosSignalConstants::DAEMON_MASTER_MEASUREMENTS,
            HilosSignalConstants::DAEMON_MASTER_REPORT_NOW,
            HilosSignalConstants::DAEMON_MASTER_REPORT_NOW,
        ], $this->names());
        self::assertSame('1234567890123456', $this->server->delivered[4]->signal->data->data->correlationId);
        self::assertSame('second', $this->server->delivered[5]->signal->data->data->correlationId);
        self::assertSame([], $this->commands->replies);
        $this->loop->tick();
        $this->roster();
        $this->invoke('dispatchCompletedNodeReport');
        self::assertCount(6, $this->server->delivered);
    }

    public function testRealProducerMutationSupersedesTheUnfinishedBasis(): void
    {
        $this->agents(1000);
        $this->roster();
        self::assertSame([], $this->server->delivered);
        $this->agentManager()->removeAgent('replica:0500');
        $this->start('replica:new');
        $this->invoke('reconcileProcessRosterSources');
        self::assertTrue($this->runnable(), 'A synchronous fact after the phase survives an empty native queue');
        $this->finishRoster();
        self::assertCount(1, $this->server->delivered);
        $agents = $this->server->delivered[0]->signal->data->data->roster->workers[0]->agents;
        self::assertCount(1001, $agents);
        self::assertSame('replica:0499', $agents[500]->id);
        self::assertSame('replica:0501', $agents[501]->id);
        self::assertSame('replica:new', $agents[1000]->id);
        self::assertSame(2, $this->server->rosterReads, 'The prefix was discarded and a complete new basis captured');
    }

    public function testAProducerChangeDuringTheFinalPortionCannotPublishAMixedFrame(): void
    {
        $this->server->duringCapture = function (): void {
            $this->start('replica:arrived');
        };
        $this->roster();
        self::assertSame([], $this->server->delivered);
        self::assertTrue($this->loop->hasReadyWork());
        $this->finishRoster();
        self::assertCount(1, $this->server->delivered);
        self::assertSame('replica:arrived', $this->server->delivered[0]->signal->data->data->roster->workers[0]->agents[1]->id);
        self::assertSame(2, $this->server->rosterReads);
    }

    public function testFutureRepairAndCoalesceKeepTheirBasisThroughRealReadableIo(): void
    {
        $this->finishRoster();
        $repair = $this->timer()->getDeadline();
        self::assertSame($this->get('processRosterLastSentAt') + 60, $repair);
        $captures = $this->agentManager()->captures;
        $generation = $this->timerGeneration();
        for ($pass = 0; $pass < 3; ++$pass) {
            $this->readableWake();
            $this->roster();
            self::assertSame($repair, $this->timer()->getDeadline());
            self::assertSame($generation, $this->timerGeneration());
        }
        self::assertSame($captures, $this->agentManager()->captures);
        self::assertSame(1, $this->server->rosterReads);
        $this->start('replica:late');
        $this->invoke('reconcileProcessRosterSources');
        $changedAt = $this->get('processRosterChangedAt');
        $coalesce = $changedAt + 0.5;
        self::assertSame($coalesce, $this->timer()->getDeadline());
        $generation = $this->timerGeneration();
        $this->readableWake();
        $this->roster();
        self::assertFalse($this->runnable());
        self::assertSame($coalesce, $this->timer()->getDeadline());
        self::assertSame($changedAt, $this->get('processRosterChangedAt'));
        self::assertSame($generation, $this->timerGeneration());
        self::assertSame(1, $this->server->rosterReads);
    }

    public function testOverdueRepairBasisBeforeBindCuesButDoesNoBusinessUntilGuard(): void
    {
        $this->finishRoster();
        $past = hrtime(true) / 1e9 - 61;
        $this->set('processRosterLastSentAt', $past);
        $this->set('processRosterChangedAt', $past);
        $this->invoke('attachProcessRosterEventLoop');
        $this->loop->tick();
        self::assertCount(1, $this->server->delivered);
        self::assertSame(1, $this->server->rosterReads);
        self::assertTrue($this->runnable());
        $this->roster();
        self::assertCount(2, $this->server->delivered);
        self::assertSame(2, $this->server->rosterReads);
        self::assertFalse($this->runnable());
    }

    public function testFailureRetryDoesNotMoveAndExplicitForceBypassesIt(): void
    {
        $this->server->problem = 'unavailable';
        $this->ask('held');
        $this->roster();
        self::assertSame([], $this->server->delivered);
        self::assertCount(1, $this->commands->replies);
        self::assertSame('The node could not build its process roster: LogicException: unavailable',
            $this->commands->replies[0]->payload[CommandConstants::FIELD_MESSAGE]);
        $retry = $this->get('processRosterRetryAt');
        self::assertSame($retry, $this->timer()->getDeadline());
        $this->readableWake();
        $this->roster();
        self::assertSame($retry, $this->timer()->getDeadline());
        self::assertSame(1, $this->server->rosterReads);
        $this->start('replica:during-retry');
        $this->invoke('reconcileProcessRosterSources');
        self::assertSame($retry, $this->timer()->getDeadline(), 'A changed basis cannot postpone the original retry');
        $this->ask('same-problem');
        $this->roster();
        self::assertCount(2, $this->commands->replies);
        self::assertSame(1, substr_count(file_get_contents($this->logFile), 'Daemon process roster build failed:'));
        $this->server->problem = 'changed';
        $this->ask('new-problem');
        $this->roster();
        self::assertSame(2, substr_count(file_get_contents($this->logFile), 'Daemon process roster build failed:'));
        $this->server->problem = null;
        $this->agentManager()->removeAgent(HilosAgentType::HILOS_DAEMON_NODE);
        $this->invoke('reconcileProcessRosterSources');
        self::assertFalse($this->runnable());
        self::assertNull($this->timer()->getDeadline());
        $this->start(HilosAgentType::HILOS_DAEMON_NODE);
        $this->finishRoster();
        self::assertCount(1, $this->server->delivered, 'Actual Started bypasses the retained failure retry');
        self::assertSame(1, substr_count(file_get_contents($this->logFile), 'Daemon process roster build recovered'));
    }

    public function testProtectedActionsSyncRemountAndNullResumeWithoutADormantLoop(): void
    {
        Hilos::$rt = new RosterNativeRuntime();
        Hilos::$rt->mountFeatureItem(ProtectedModeRuntime::RT_ITEM, ProtectedModeRuntime::create());
        RtTruthSourceRegistry::registerDaemon(ProtectedModeRuntime::RT_ITEM);
        Hilos::$rt->hilosProtectedModeRuntime->actions->enterActive();
        $this->invoke('reconcileProcessRosterSources');
        $this->loop->tick();
        self::assertFalse($this->runnable());
        self::assertFalse($this->loop->hasReadyWork());
        self::assertNull($this->timer()->getDeadline());
        self::assertTrue($this->get('processRosterForce'));
        self::assertSame(0, $this->server->rosterReads);
        Hilos::$rt->hilosProtectedModeRuntime->actions->enterInactive();
        $this->invoke('reconcileProcessRosterSources');
        self::assertTrue($this->runnable());
        $this->finishRoster();
        self::assertCount(1, $this->server->delivered);
        RtSyncApplicator::applyUpdated(new RtSyncUpdatedSignalData(ProtectedModeRuntime::RT_ITEM, ProtectedModeRuntime::ID, [
            ProtectedModeRuntime::phase => ProtectedModeRuntime::PHASE_ACTIVE,
        ]), false);
        $this->roster();
        self::assertFalse($this->loop->hasReadyWork());
        self::assertFalse($this->runnable());
        RtSnapshot::replace(ProtectedModeRuntime::RT_ITEM, [ProtectedModeRuntime::ID => ProtectedModeRuntime::create()->toArray()]);
        $this->invoke('reconcileProcessRosterSources');
        $this->finishRoster();
        self::assertCount(2, $this->server->delivered);
        Hilos::$rt = new RosterNativeRuntime();
        Hilos::$rt->mountFeatureItem(ProtectedModeRuntime::RT_ITEM, ProtectedModeRuntime::create());
        $this->invoke('reconcileProcessRosterSources');
        $this->finishRoster();
        self::assertCount(3, $this->server->delivered);
        Hilos::$rt = null;
        $this->invoke('reconcileProcessRosterSources');
        $this->finishRoster();
        self::assertCount(4, $this->server->delivered, 'Null is no local state, not an invented freeze');
    }

    public function testFreezeCancelsAPartialBuildAndLateSourceAndStartedReturnIt(): void
    {
        $this->agents(1000);
        $this->roster();
        Hilos::$rt = new RosterNativeRuntime();
        Hilos::$rt->mountFeatureItem(ProtectedModeRuntime::RT_ITEM, ProtectedModeRuntime::create());
        RtTruthSourceRegistry::registerDaemon(ProtectedModeRuntime::RT_ITEM);
        Hilos::$rt->hilosProtectedModeRuntime->actions->enterActive();
        $this->roster();
        self::assertFalse($this->get('processRosterBuilding'));
        self::assertSame([], $this->server->delivered);
        $this->set('servers', [$this->commands]);
        Hilos::$rt->hilosProtectedModeRuntime->actions->enterInactive();
        $this->roster();
        self::assertFalse($this->runnable());
        self::assertFalse($this->loop->hasReadyWork());
        $this->agentManager()->removeAgent(HilosAgentType::HILOS_DAEMON_NODE);
        $this->manager->registerServer($this->server);
        $this->invoke('reconcileProcessRosterSources');
        self::assertFalse($this->runnable());
        $this->start(HilosAgentType::HILOS_DAEMON_NODE);
        $this->invoke('reconcileProcessRosterSources');
        $this->finishRoster();
        self::assertCount(1, $this->server->delivered);
        self::assertCount(1001, $this->server->delivered[0]->signal->data->data->roster->workers[0]->agents);
    }

    public function testProtectedFactAfterReportPhaseIsKeptEvenWhenTheFinalPhaseIsUnchanged(): void
    {
        Hilos::$rt = new RosterNativeRuntime();
        Hilos::$rt->mountFeatureItem(ProtectedModeRuntime::RT_ITEM, ProtectedModeRuntime::create());
        RtTruthSourceRegistry::registerDaemon(ProtectedModeRuntime::RT_ITEM);
        $binding = $this->get('protectedModeSourceBinding');
        $binding->registerWatchdog($this->get('protectedModeWatchdog'));
        $this->manager->reconcileProtectedModeSource();
        $this->finishRoster();
        self::assertFalse($this->loop->hasReadyWork());
        $revision = $binding->revision();
        Hilos::$rt->hilosProtectedModeRuntime->actions->enterActive();
        Hilos::$rt->hilosProtectedModeRuntime->actions->enterInactive();
        self::assertGreaterThan($revision, $binding->revision());
        self::assertFalse($this->loop->hasReadyWork(), 'The source fact need not own another native callback');
        $this->invoke('reconcileProcessRosterSources');
        self::assertTrue($this->runnable(), 'Pre-wait reconciliation preserves the late source fact');
        $this->finishRoster();
        self::assertCount(2, $this->server->delivered);
        $binding->removeWatchdog($this->get('protectedModeWatchdog'));
    }

    public function testSameSourceRebindKeepsCursorAndOriginalFutureDeadline(): void
    {
        $this->agents(1000);
        $this->roster();
        $old = $this->timer();
        $escaped = $this->timerCallback($old);
        $this->invoke('attachProcessRosterEventLoop');
        $this->finishRoster();
        self::assertSame(1, $this->server->rosterReads, 'Rebinding handles does not recapture a live source');
        $deadline = $this->timer()->getDeadline();
        self::assertNull($old->getDeadline());
        $this->invoke('attachProcessRosterEventLoop');
        self::assertSame($deadline, $this->timer()->getDeadline());
        $escaped();
        self::assertFalse($this->runnable());
        $this->invoke('detachProcessRosterEventLoop');
        $this->loop->tick();
        self::assertFalse($this->loop->hasReadyWork());
        $escaped();
        self::assertFalse($this->runnable());
        self::assertFalse($this->get('processRosterDue'));
    }

    public function testReplacingServerAndAgentManagerDiscardsTheOldGraphAndPrefix(): void
    {
        $this->agents(1000);
        $this->roster();
        $oldServer = WeakReference::create($this->server);
        $oldAgents = WeakReference::create($this->agentManager());
        $this->set('servers', [$this->commands]);
        $this->set('agentManagerDaemon', new RosterNativeAgents());
        $this->server = new RosterNativeWorkerServer();
        $this->manager->registerServer($this->server);
        $this->agentManager()->registerAgentStartSink($this->manager);
        $this->start(HilosAgentType::HILOS_DAEMON_NODE);
        $this->invoke('reconcileProcessRosterSources');
        self::assertNotSame($oldServer->get(), $this->get('processRosterWorkerSource')->get());
        self::assertNotSame($oldAgents->get(), $this->get('processRosterAgentSource')->get());
        self::assertNull(new ReflectionProperty($this->get('processRosterBuilder')::class, 'agentManager')
            ->getValue($this->get('processRosterBuilder')), 'The canceled cursor releases its old source');
        $this->finishRoster();
        self::assertCount(1, $this->server->delivered);
        self::assertCount(1, $this->server->delivered[0]->signal->data->data->roster->workers[0]->agents);
    }

    public function testPlacementRevisionAndIdentitySupersedeTheLeaderBuildAndFollowerStillReports(): void
    {
        $this->agents(1000);
        $cluster = new ClusterContext();
        $cluster->registerLeadership(new StandaloneLeadership());
        new ReflectionProperty(ClusterContext::class, 'identity')->setValue($cluster, NodeIdentity::of('a', 'A', NodeRole::Master, []));
        Hilos::$cluster = $cluster;
        $placement = $this->placement();
        $cluster->registerPlacement($placement);
        $placement->registry()->put(new PlacementRecord('replica', 'unplaced', null, PlacementState::Unplaced));
        $this->roster();
        $placement->registry()->forget('replica:unplaced');
        $placement->registry()->put(new PlacementRecord('replica', 'latest', null, PlacementState::Refused));
        $this->invoke('reconcileProcessRosterSources');
        $this->finishRoster();
        self::assertSame(['replica:latest'], $this->server->delivered[0]->signal->data->data->roster->unplacedAgentIds);
        $other = $this->placement();
        $other->registry()->put(new PlacementRecord('replica', 'replacement', null, PlacementState::Stopped));
        $cluster->registerPlacement($other);
        $this->ask('replacement');
        $this->finishRoster();
        self::assertSame(['replica:replacement'], $this->server->delivered[1]->signal->data->data->roster->unplacedAgentIds);
        $follower = $this->createStub(Leadership::class);
        $follower->method('amLeader')->willReturn(false);
        $cluster->registerLeadership($follower);
        $this->ask('follower');
        $this->finishRoster();
        self::assertNull($this->server->delivered[2]->signal->data->data->roster->unplacedAgentIds);
    }

    public function testUnreadableLeadershipNeverPublishesAnAuthoritativeEmptyList(): void
    {
        $leader = $this->createStub(Leadership::class);
        $leader->method('amLeader')->willThrowException(new LogicException('unreadable leader'));
        Hilos::$cluster = new ClusterContext();
        Hilos::$cluster->registerLeadership($leader);
        $this->ask('unreadable');
        $this->roster();
        self::assertSame([], $this->server->delivered);
        self::assertSame(0, $this->server->rosterReads);
        self::assertFalse($this->runnable());
        self::assertGreaterThan(hrtime(true) / 1e9, $this->timer()->getDeadline());
    }

    public function testARequestAfterAnEarlierSendGetsAFreshEpochCompletion(): void
    {
        $this->finishRoster();
        $sentAt = $this->get('nodeReportRosterCompletedAt');
        $this->ask('newer');
        self::assertNull($this->get('nodeReportRosterCompletedAt'));
        $this->invoke('dispatchCompletedNodeReport');
        self::assertCount(1, $this->server->delivered);
        $this->finishRoster();
        self::assertGreaterThanOrEqual($sentAt, $this->get('nodeReportRosterCompletedAt'));
        $this->otherFrames();
        self::assertSame([
            HilosSignalConstants::DAEMON_MASTER_PROCESS_ROSTER,
            HilosSignalConstants::DAEMON_MASTER_PROCESS_ROSTER,
            HilosSignalConstants::DAEMON_MASTER_CRON,
            HilosSignalConstants::DAEMON_MASTER_STANDING,
            HilosSignalConstants::DAEMON_MASTER_MEASUREMENTS,
            HilosSignalConstants::DAEMON_MASTER_REPORT_NOW,
        ], $this->names());
        self::assertSame('newer', $this->server->delivered[5]->signal->data->data->correlationId);
    }

    public function testAnAvailableHttpFrameIsOwedBeforeReportNow(): void
    {
        $this->set('httpRouter', new HttpRouter());
        $this->manager->registerServer(new HttpServer('127.0.0.1', 8080));
        $this->ask('http');
        $this->finishRoster();
        $this->invoke('attachReportFramesEventLoop');
        $this->invoke('attachMeasurementsEventLoop');
        $this->loop->tick();
        $this->invoke('dispatchCronFrame');
        $this->invoke('dispatchStanding');
        $this->invoke('dispatchMeasurements');
        $this->invoke('dispatchCompletedNodeReport');
        self::assertSame([
            HilosSignalConstants::DAEMON_MASTER_PROCESS_ROSTER,
            HilosSignalConstants::DAEMON_MASTER_CRON,
            HilosSignalConstants::DAEMON_MASTER_STANDING,
            HilosSignalConstants::DAEMON_MASTER_MEASUREMENTS,
        ], $this->names());
        $this->invoke('dispatchHttpFrame');
        $this->invoke('dispatchCompletedNodeReport');
        self::assertSame(HilosSignalConstants::DAEMON_MASTER_HTTP, $this->names()[4]);
        self::assertSame(HilosSignalConstants::DAEMON_MASTER_REPORT_NOW, $this->names()[5]);
        self::assertSame('http', $this->server->delivered[5]->signal->data->data->correlationId);
    }

    public function testNativeWholeCompletionRefusesAnOriginalExpiredWaitBeforeForwarding(): void
    {
        $owner = $this->get('daemonNodeReportTestRequests');
        $owner->request(new CommandRequestDTO('expired', CliCommands::DAEMON_TEST_NODE_REPORT, []), microtime(true),
            hrtime(true) / 1e9 - CommandChannelWindows::AGENT_WAIT_SECONDS);
        $this->finishRoster();
        $this->otherFrames();
        self::assertSame([
            HilosSignalConstants::DAEMON_MASTER_PROCESS_ROSTER,
            HilosSignalConstants::DAEMON_MASTER_CRON,
            HilosSignalConstants::DAEMON_MASTER_STANDING,
            HilosSignalConstants::DAEMON_MASTER_MEASUREMENTS,
        ], $this->names());
        self::assertCount(1, $this->commands->replies);
        self::assertSame('expired', $this->commands->replies[0]->correlationId);
        self::assertSame('The Daemon node agent did not report in time.', $this->commands->replies[0]->payload[CommandConstants::FIELD_MESSAGE]);
    }

    public function testExitAndTerminalDetachCancelActivatedWork(): void
    {
        $this->set('shouldExit', true);
        $this->invoke('reconcileProcessRosterSources');
        $this->loop->tick();
        $this->roster();
        self::assertSame([], $this->server->delivered);
        self::assertFalse($this->runnable());
        self::assertFalse($this->loop->hasReadyWork());
        $this->invoke('detachProcessRosterEventLoop');
        self::assertNull($this->get('processRosterTimer'));
        self::assertNull($this->get('processRosterWork'));
    }

    public function testNativeHandlesDoNotRetainTheirManager(): void
    {
        // Only the roster dependencies are needed; independent constructor-owned command closures
        // have their own lifetimes and must not turn this into a test of cyclic graph collection.
        $manager = new ReflectionClass(RosterNativeManager::class)->newInstanceWithoutConstructor();
        foreach (['eventLoop' => $this->loop, 'servers' => [$this->server],
            'agentManagerDaemon' => $this->agentManager(), 'agentRestarts' => $this->get('agentRestarts'),
            'protectedModeSourceBinding' => $this->get('protectedModeSourceBinding'),
            'processRosterBuilder' => new DaemonProcessRosterBuilder(new ProcessMetricsReader())] as $name => $value) {
            new ReflectionProperty(DaemonManager::class, $name)->setValue($manager, $value);
        }
        new ReflectionMethod(DaemonManager::class, 'attachProcessRosterEventLoop')->invoke($manager);
        $weak = WeakReference::create($manager);
        unset($manager);
        self::assertNull($weak->get());
        $this->loop->tick();
        self::assertSame([], $this->server->delivered);
    }

    protected function setUp(): void
    {
        $this->previousCluster = Hilos::$cluster;
        $this->previousRuntime = Hilos::$rt;
        $this->previousRouter = Hilos::$sr;
        $this->previousEnv = Hilos::$env;
        $this->previousApp = Hilos::appClass();
        $this->previousEnabled = getenv(EnvConstants::CLUSTER_ENABLED->name);
        $this->previousAppEnv = getenv('APP_ENV');
        putenv(EnvConstants::CLUSTER_ENABLED->name . '=false');
        putenv('APP_ENV=test');
        Hilos::$cluster = null;
        Hilos::$rt = null;
        Hilos::$env = new EnvAccessor();
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, RosterNativeHilos::class);
        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-roster-native-');
        Logger::setLogFile($this->logFile);
        $this->loop = new EventLoop();
        $this->manager = new RosterNativeManager();
        $this->server = new RosterNativeWorkerServer();
        $this->commands = new RosterNativeCommandServer();
        $this->manager->registerServer($this->server);
        $this->manager->registerServer($this->commands);
        $this->agent = $this->createStub(AgentDaemonInterface::class);
        $this->agent->method('hasWorkerClient')->willReturn(true);
        $this->agent->method('getWorkerClient')->willReturn($this->createStub(WorkerClient::class));
        $this->start(HilosAgentType::HILOS_DAEMON_NODE);
        $this->set('eventLoop', $this->loop);
        $this->invoke('attachProcessRosterEventLoop');
    }

    protected function tearDown(): void
    {
        if (isset($this->manager)) {
            $this->invoke('detachMeasurementsEventLoop');
            $this->invoke('detachReportFramesEventLoop');
            $this->invoke('detachProcessRosterEventLoop');
        }
        $this->loop->cleanup();
        foreach ($this->sockets as $socket) {
            socket_close($socket);
        }
        RtTruthSourceRegistry::unregisterDaemon(ProtectedModeRuntime::RT_ITEM);
        Logger::resetLogFile();
        unlink($this->logFile);
        Hilos::$cluster = $this->previousCluster;
        Hilos::$rt = $this->previousRuntime;
        Hilos::$sr = $this->previousRouter;
        Hilos::$env = $this->previousEnv;
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->previousApp);
        putenv(EnvConstants::CLUSTER_ENABLED->name . ($this->previousEnabled === false ? '' : '=' . $this->previousEnabled));
        putenv($this->previousAppEnv === false ? 'APP_ENV' : 'APP_ENV=' . $this->previousAppEnv);
        parent::tearDown();
    }

    /** @param int $count Actual started producer rows, added in reverse sort order */
    private function agents(int $count): void
    {
        for ($index = $count - 1; $index >= 0; --$index) {
            $this->start('replica:' . sprintf('%04d', $index));
        }
    }

    /** @param string $id Real manager membership and actual Started mutation */
    private function start(string $id): void
    {
        $this->agentManager()->addAgent($id, $this->agent, 1, false);
        $identity = AgentId::fromId($id);
        $this->agentManager()->handleAgentStarted(new WorkerAgentStartedDTO($id, $identity->type, $identity->index));
    }

    /** @return RosterNativeAgents The actual revision-producing agent manager */
    private function agentManager(): RosterNativeAgents
    {
        return $this->get('agentManagerDaemon');
    }

    /** @param string $correlation Original console correlation */
    private function ask(string $correlation): void
    {
        $this->manager->requestNodeReport(new CommandRequestDTO($correlation, CliCommands::DAEMON_TEST_NODE_REPORT, []));
    }

    /** One existing guarded business phase; native callbacks only cue it. */
    private function roster(): void
    {
        $this->invoke('dispatchProcessRoster');
    }

    /** @return int Finite ready portions required, with no input and no blanket iteration */
    private function finishRoster(): int
    {
        $this->invoke('reconcileProcessRosterSources');
        $passes = 0;
        while (($this->loop->hasReadyWork() || $this->runnable()) && $passes < 50) {
            $this->loop->tick();
            $this->roster();
            ++$passes;
        }
        self::assertLessThan(50, $passes);
        self::assertFalse($this->runnable());
        self::assertFalse($this->loop->hasReadyWork());
        return $passes;
    }

    /** Bind and drive all actual native report phases before whole completion. */
    private function otherFrames(): void
    {
        $this->invoke('attachReportFramesEventLoop');
        $this->invoke('attachMeasurementsEventLoop');
        $this->loop->tick();
        $this->invoke('dispatchCronFrame');
        $this->invoke('dispatchStanding');
        $this->invoke('dispatchHttpFrame');
        $this->invoke('dispatchMeasurements');
        $this->invoke('dispatchCompletedNodeReport');
    }

    /** @return list<string> Every delivered frame, in its exact transport order */
    private function names(): array
    {
        return array_map(static fn (DaemonAgentMessageDTO $frame): string => $frame->signal->signalName->getName(), $this->server->delivered);
    }

    /** One guaranteed-readable socket permits runOnce without waiting out a future timer. */
    private function readableWake(): void
    {
        $pair = [];
        self::assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        array_push($this->sockets, ...$pair);
        $read = false;
        $this->loop->registerRead($pair[0], static function () use ($pair, &$read): void {
            $read = socket_read($pair[0], 1) === 'x';
        });
        socket_write($pair[1], 'x');
        $this->loop->runOnce();
        self::assertTrue($read);
    }

    /** @return bool Actual synchronous driver predicate */
    private function runnable(): bool
    {
        return $this->invoke('hasRunnableProcessRoster');
    }

    /** @return EventLoopTimer Current native deadline owner */
    private function timer(): EventLoopTimer
    {
        return $this->get('processRosterTimer');
    }

    /** @return int Native generation proving an equal future arm was retained */
    private function timerGeneration(): int
    {
        return new ReflectionProperty(EventLoopTimer::class, 'generation')->getValue($this->timer());
    }

    /**
     * @param EventLoopTimer $timer Retiring timer
     * @return Closure Escaped owner callback for generation rejection
     */
    private function timerCallback(EventLoopTimer $timer): Closure
    {
        return new ReflectionProperty(EventLoopTimer::class, 'callback')->getValue($timer);
    }

    /** @return ClusterPlacement Actual registry producer, with inert transport and executor */
    private function placement(): ClusterPlacement
    {
        return new ClusterPlacement('a', $this->createStub(PlacementMesh::class), $this->createStub(PlacementExecutor::class));
    }

    /**
     * @param string $method Existing guarded/private phase
     * @param mixed ...$arguments Phase arguments
     * @return mixed Phase result
     */
    private function invoke(string $method, mixed ...$arguments): mixed
    {
        return new ReflectionMethod(DaemonManager::class, $method)->invoke($this->manager, ...$arguments);
    }

    /**
     * @param string $property Private owner state
     * @return mixed Current value
     */
    private function get(string $property): mixed
    {
        return new ReflectionProperty(DaemonManager::class, $property)->getValue($this->manager);
    }

    /**
     * @param string $property Existing private basis, prepared before native arming
     * @param mixed $value Business basis or lifetime binding
     */
    private function set(string $property, mixed $value): void
    {
        new ReflectionProperty(DaemonManager::class, $property)->setValue($this->manager, $value);
    }
}

/** Only the real report phases run; this fixture never starts a daemon or worker process. */
final class RosterNativeManager extends DaemonManager
{
    /** @return SignalRouter Empty topology for addressed master frames */
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    /** @return AgentManagerDaemon Real production membership and Started behavior */
    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new RosterNativeAgents();
    }
}

/** Capture count distinguishes scalar reconciliation from a full source snapshot. */
final class RosterNativeAgents extends AgentManagerDaemon
{
    public int $captures = 0;

    /** @return array<string, AgentDaemonInterface> Production roster snapshot */
    public function getAgents(): array
    {
        ++$this->captures;
        return parent::getAgents();
    }

    /**
     * @param string $agentType Unused factory type
     * @param ?string $agentIndex Unused factory index
     * @return AgentDaemonInterface Never returned: tests supply inert agent instances at addAgent
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new LogicException('No worker factory is needed by a roster reader');
    }
}

/** Synthetic kernel-free workers, real roster builder and exact addressed output. */
final class RosterNativeWorkerServer extends WorkerServer
{
    public int $rosterReads = 0;
    public ?string $problem = null;
    public ?Closure $duringCapture = null;
    /** @var list<DaemonAgentMessageDTO> Exact output, never filtered by signal */
    public array $delivered = [];

    public function __construct()
    {
    }

    /** @return list<DaemonWorkerPicture> One known worker, without a kernel PID */
    public function liveWorkerPictures(): array
    {
        ++$this->rosterReads;
        if ($this->problem !== null) {
            throw new LogicException($this->problem);
        }
        $duringCapture = $this->duringCapture;
        $this->duringCapture = null;
        $duringCapture?->__invoke();
        return [new DaemonWorkerPicture(1, WorkerConstants::TYPE_REGULAR, null, null, [])];
    }

    /**
     * @param string $agentType Receiver
     * @param ?string $agentIndex Receiver index
     * @param DaemonAgentMessageDTO $messageDto Original message on the local worker link
     */
    public function sendSignalToAgent(string $agentType, ?string $agentIndex, DaemonAgentMessageDTO $messageDto): void
    {
        $this->delivered[] = $messageDto;
    }

    /** No listener or worker process is needed by a roster reader. */
    protected function onStart(): void
    {
    }
}

/** Existing command-channel boundary, capturing build refusals without a socket. */
final class RosterNativeCommandServer extends CommandServer
{
    /** @var list<CommandReplyDTO> Replies on the original command origin */
    public array $replies = [];

    public function __construct()
    {
        $this->port = 0;
    }

    /**
     * @param string $correlationId Original caller
     * @param CommandReplyDTO $reply Original refusal
     */
    public function deliver(string $correlationId, CommandReplyDTO $reply): void
    {
        $this->replies[] = $reply;
    }
}

/** Framework singleton is mounted explicitly, through the normal runtime seam. */
final class RosterNativeRuntime extends RtContext
{
    public function configure(): void
    {
    }
}

/** The two real agent kinds appearing in these whole reports. */
abstract class RosterNativeHilos extends Hilos
{
    public const array AGENTS = [
        HilosAgentType::HILOS_DAEMON_NODE => [AgentRegistryKey::SCOPE => AgentScope::NODE],
        'replica' => [AgentRegistryKey::SCOPE => AgentScope::NODE, AgentRegistryKey::INDEXED => true],
    ];
}
