<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\StandaloneLeadership;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Daemon\DaemonMeasurements;
use Hilos\Core\Daemon\Master\DaemonStatus;
use Hilos\Core\Daemon\NodeLimitsReader;
use Hilos\Core\Daemon\ProcessMetricsReader;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\EventLoop\EventLoopTimer;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\DaemonSection\DaemonWorkerPicture;
use Hilos\DaemonSection\DTO\DaemonMasterMeasurementsSignalData;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Runtime\RtSnapshot;
use Hilos\Runtime\RtSyncApplicator;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentStartedDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Logger;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Socket;
use WeakReference;

/** Actual native cues and guarded sampling over real readers of synthetic kernel pseudo-files. */
final class DaemonManagerMeasurementsNativeTest extends TestCase
{
    private EventLoop $loop;
    private MeasurementsNativeManager $manager;
    private MeasurementsNativeWorkers $workers;
    private MeasurementsNativeStatus $status;
    private AgentDaemonInterface $agent;
    private string $root;
    private string $logFile;
    private ?ClusterContext $previousCluster;
    private ?RtContext $previousRuntime;
    private ?SignalRouter $previousRouter;
    private ?EnvAccessor $previousEnv;
    private string $previousApp;
    private string|false $previousEnabled;
    /** @var list<Socket> Closed after their native watches */
    private array $sockets = [];

    public function testFirstSampleAndOriginalDeadlineSurviveForceReadableIoAndSameSourceRebind(): void
    {
        self::assertSame(0, $this->status->samples);
        $this->loop->tick();
        self::assertSame(0, $this->status->samples, 'Native callbacks never sample or send');
        self::assertSame(0, $this->workers->pidReads);
        self::assertSame([], $this->workers->delivered);
        $this->dispatch();
        self::assertSame(1, $this->status->samples);
        self::assertSame(1, $this->workers->pidReads);
        self::assertCount(1, $this->workers->delivered);
        $sampled = $this->get('measurementsSampledAt');
        $timer = $this->get('measurementSampleTimer');
        $deadline = $timer->getDeadline();
        self::assertSame($sampled + 5, $deadline);
        self::assertSame($sampled, $this->previousSampleTime());
        self::assertLessThan(microtime(true), $sampled, 'Sampler and epoch uptime use distinct bases');
        self::assertSame(100, $this->frame(0)->measurements->startedAt);
        self::assertNull($this->frame(0)->measurements->masterCores);
        self::assertNull($this->frame(0)->measurements->daemonCores);
        $repair = $this->get('measurementReportTimer')->getDeadline();
        $generation = new ReflectionProperty(EventLoopTimer::class, 'generation')->getValue($timer);
        for ($pass = 0; $pass < 3; ++$pass) {
            $timer->armAt($deadline);
            $this->readableWake();
            $this->dispatch();
            self::assertSame($deadline, $timer->getDeadline());
            self::assertSame($repair, $this->get('measurementReportTimer')->getDeadline());
            self::assertSame($generation, new ReflectionProperty(EventLoopTimer::class, 'generation')->getValue($timer));
        }
        $escaped = $this->timerCue($timer);
        $this->invoke('attachMeasurementsEventLoop');
        $escaped();
        self::assertFalse($this->get('measurementSampleDue'));
        self::assertSame($deadline, $this->get('measurementSampleTimer')->getDeadline());
        self::assertSame($sampled, $this->previousSampleTime());
        $this->invoke('forceDaemonNodeFrames');
        $this->loop->tick();
        self::assertCount(1, $this->workers->delivered);
        $this->dispatch();
        self::assertSame(1, $this->status->samples);
        self::assertSame(1, $this->workers->pidReads);
        self::assertCount(2, $this->workers->delivered);
        self::assertSame($this->frame(0)->measurements, $this->frame(1)->measurements);
        self::assertSame($deadline, $this->get('measurementSampleTimer')->getDeadline());
        self::assertFalse($this->invoke('hasRunnableMeasurements'));
        self::assertFalse($this->loop->hasReadyWork());
    }

    public function testActualSamplerUsesMonotonicCpuDeltasAndPreservesEpochAndStatusConsumers(): void
    {
        $this->dispatch();
        $this->process(10, 160, 550);
        $this->process(20, 140, 700);
        $this->put('proc/stat', "cpu 140 0 0 150 0 10 0 0\n");
        $past = $this->ageSampler();
        $before = $this->status->samples;
        $this->loop->tick();
        self::assertSame($before, $this->status->samples);
        self::assertSame(1, $this->workers->pidReads);
        self::assertTrue($this->invoke('hasRunnableMeasurements'));
        $this->dispatch();
        self::assertSame(2, $this->status->samples);
        self::assertSame(2, $this->workers->pidReads);
        $sample = $this->status->last();
        self::assertSame(0.12, $sample->masterCores);
        self::assertSame(0.3, $sample->daemonCores);
        self::assertSame(50, $sample->machineLoad);
        self::assertSame(100, $sample->startedAt);
        self::assertSame(1.5, $sample->cores);
        self::assertSame(DaemonMeasurements::CORES_QUOTA, $sample->coresBound);
        self::assertSame(2097152, $sample->masterMemory);
        self::assertSame(2097152, $sample->workersMemory);
        self::assertSame(1, $sample->workersMeasured);
        self::assertSame(4194304, $sample->memoryCeiling);
        self::assertSame(DaemonMeasurements::CEILING_CGROUP, $sample->memoryCeilingBound);
        self::assertGreaterThan($past, $this->previousSampleTime());
        self::assertSame($sample, $this->manager->daemonStatusSnapshot()->measurements);
        self::assertSame((int)(microtime(true) - 100), $this->status->getUptime());
        self::assertSame('standalone', $this->frame(1)->nodeId);
        self::assertEquals($this->frame(1), DaemonMasterMeasurementsSignalData::fromArray($this->frame(1)->toArray()));
        self::assertSame($sample, $this->frame(1)->measurements);
        $this->dispatch();
        self::assertSame(2, $this->status->samples);
        self::assertCount(2, $this->workers->delivered);
    }

    public function testUnchangedSampleDoesNotMoveTheIndependentOriginalMinuteRepair(): void
    {
        $this->dispatch();
        $this->ageSampler();
        $this->loop->tick();
        $this->dispatch();
        self::assertSame(0.0, $this->status->last()->masterCores);
        self::assertCount(1, $this->workers->delivered, 'Preserve the existing whole-picture equality');
        $repair = $this->get('measurementReportTimer')->getDeadline();
        $this->ageSampler();
        $this->loop->tick();
        $this->dispatch();
        self::assertSame(3, $this->status->samples);
        self::assertCount(1, $this->workers->delivered);
        self::assertSame($repair, $this->get('measurementReportTimer')->getDeadline());
        self::assertFalse($this->invoke('hasRunnableMeasurements'));
        $past = hrtime(true) / 1e9 - 61;
        $this->set('measurementsLastSentAt', $past);
        $this->get('measurementReportTimer')->armAt($past + 60);
        $this->loop->tick();
        self::assertSame(3, $this->status->samples);
        self::assertCount(1, $this->workers->delivered);
        self::assertTrue($this->invoke('hasRunnableMeasurements'));
        $this->dispatch();
        self::assertSame(3, $this->status->samples);
        self::assertSame(3, $this->workers->pidReads);
        self::assertCount(2, $this->workers->delivered);
        $this->loop->tick();
        $this->dispatch();
        self::assertCount(2, $this->workers->delivered);
        self::assertFalse($this->invoke('hasRunnableMeasurements'));
    }

    public function testFrozenAndMissingReceiverKeepSamplingAndLiftOrActualStartedSendsLatestWhole(): void
    {
        $runtime = $this->runtime();
        $runtime->hilosProtectedModeRuntime->actions->enterActive();
        $this->invoke('forceDaemonNodeFrames');
        $this->dispatch();
        self::assertSame(1, $this->status->samples);
        self::assertSame([], $this->workers->delivered);
        $this->process(20, 50, 900);
        $this->ageSampler();
        $this->loop->tick();
        self::assertSame(1, $this->status->samples);
        $this->dispatch();
        self::assertSame(2, $this->status->samples);
        self::assertSame(3145728, $this->manager->daemonStatusSnapshot()->measurements->workersMemory);
        self::assertTrue($this->get('measurementsForce'));
        self::assertNull($this->get('measurementReportTimer')->getDeadline());
        self::assertGreaterThan(hrtime(true) / 1e9, $this->get('measurementSampleTimer')->getDeadline());
        self::assertFalse($this->invoke('hasRunnableMeasurements'));
        self::assertFalse($this->loop->hasReadyWork());
        $runtime->hilosProtectedModeRuntime->actions->enterInactive();
        $this->invoke('reconcileReportFrameSources');
        $this->loop->tick();
        self::assertTrue($this->invoke('hasRunnableMeasurements'));
        self::assertFalse($this->loop->hasReadyWork(), 'Synchronous due survives an empty native queue');
        $this->dispatch();
        self::assertSame(2, $this->status->samples);
        self::assertCount(1, $this->workers->delivered);
        self::assertSame(3145728, $this->frame(0)->measurements->workersMemory);
        $this->get('agentManagerDaemon')->removeAgent(HilosAgentType::HILOS_DAEMON_NODE);
        $this->invoke('forceDaemonNodeFrames');
        $this->process(20, 50, 1200);
        $this->ageSampler();
        $this->dispatch();
        $this->loop->tick();
        self::assertSame(3, $this->status->samples);
        self::assertSame(4194304, $this->manager->daemonStatusSnapshot()->measurements->workersMemory);
        self::assertCount(1, $this->workers->delivered);
        self::assertTrue($this->get('measurementsForce'));
        self::assertFalse($this->invoke('hasRunnableMeasurements'));
        self::assertFalse($this->loop->hasReadyWork());
        $this->startNode();
        $this->dispatch();
        self::assertSame(3, $this->status->samples);
        self::assertSame(3, $this->workers->pidReads);
        self::assertCount(2, $this->workers->delivered);
        self::assertSame(4194304, $this->frame(1)->measurements->workersMemory);
    }

    public function testTransientProtectedRevisionSyncSnapshotRemountAndNullAreCausalFacts(): void
    {
        $runtime = $this->runtime();
        $this->dispatch();
        $sampleAt = $this->get('measurementsSampledAt');
        $deadline = $this->get('measurementSampleTimer')->getDeadline();
        $escaped = $this->timerCue($this->get('measurementReportTimer'));
        $runtime->hilosProtectedModeRuntime->actions->enterActive();
        $runtime->hilosProtectedModeRuntime->actions->enterInactive();
        $escaped();
        self::assertFalse($this->get('measurementReportDue'), 'Old callbacks reject even a same-final-phase revision');
        self::assertFalse($this->loop->hasReadyWork());
        $this->invoke('reconcileReportFrameSources');
        $this->loop->tick();
        self::assertTrue($this->invoke('hasRunnableMeasurements'));
        self::assertFalse($this->loop->hasReadyWork());
        $this->dispatch();
        self::assertCount(2, $this->workers->delivered);
        RtSyncApplicator::applyUpdated(new RtSyncUpdatedSignalData(ProtectedModeRuntime::RT_ITEM, ProtectedModeRuntime::ID, [
            ProtectedModeRuntime::phase => ProtectedModeRuntime::PHASE_ACTIVE,
        ]));
        $this->dispatch();
        $this->loop->tick();
        self::assertCount(2, $this->workers->delivered);
        self::assertFalse($this->invoke('hasRunnableMeasurements'));
        RtSnapshot::replace(ProtectedModeRuntime::RT_ITEM, [ProtectedModeRuntime::ID => ProtectedModeRuntime::create()->toArray()]);
        $this->manager->reconcileProtectedModeSource();
        $this->dispatch();
        self::assertCount(3, $this->workers->delivered);
        Hilos::$rt = null;
        $this->dispatch();
        self::assertCount(4, $this->workers->delivered);
        $this->runtime();
        $this->dispatch();
        self::assertCount(5, $this->workers->delivered);
        self::assertSame(1, $this->status->samples);
        self::assertSame(1, $this->workers->pidReads);
        self::assertSame($sampleAt, $this->get('measurementsSampledAt'));
        self::assertSame($deadline, $this->get('measurementSampleTimer')->getDeadline());
    }

    /** @return iterable<string, array{bool}> Both original failure boundaries */
    public static function failures(): iterable
    {
        yield 'contained sender refusal' => [false];
        yield 'direct report catch' => [true];
    }

    #[DataProvider('failures')]
    public function testOriginalRefusalRetrySurvivesNewSamplesReasonsAndForceBypassesIt(bool $direct): void
    {
        $this->manager->directFailure = $direct;
        $this->manager->problem = 'First refusal';
        $this->workers->problem = 'First refusal';
        $this->dispatch();
        self::assertSame(1, $this->manager->measurementAttempts);
        $retry = $this->get('measurementsRetryAt');
        self::assertSame($this->get('measurementsLastSentAt') + 60, $retry);
        self::assertSame($retry, $this->get('measurementReportTimer')->getDeadline());
        $this->workers->problem = 'Changed refusal';
        $this->manager->problem = 'Changed refusal';
        $this->process(20, 50, 900);
        $this->ageSampler();
        $this->loop->tick();
        $this->dispatch();
        self::assertSame(2, $this->status->samples, 'Report retry does not hold back the sampler');
        self::assertSame(1, $this->manager->measurementAttempts);
        self::assertSame($retry, $this->get('measurementReportTimer')->getDeadline());
        self::assertSame(1, $this->refusalLogCount());
        $this->invoke('forceDaemonNodeFrames');
        $this->dispatch();
        self::assertSame(2, $this->manager->measurementAttempts);
        self::assertSame(2, $this->refusalLogCount());
        $this->invoke('forceDaemonNodeFrames');
        $this->dispatch();
        self::assertSame(3, $this->manager->measurementAttempts);
        self::assertSame(2, $this->refusalLogCount(), 'The same refusal is silent');
        $past = hrtime(true) / 1e9 - 61;
        $this->set('measurementsLastSentAt', $past);
        $this->set('measurementsRetryAt', $past + 60);
        $this->get('measurementReportTimer')->armAt($past + 60);
        $this->workers->problem = null;
        $this->manager->problem = null;
        $this->loop->tick();
        self::assertSame(3, $this->manager->measurementAttempts);
        $this->dispatch();
        self::assertSame(4, $this->manager->measurementAttempts);
        self::assertCount(1, $this->workers->delivered);
        self::assertSame(3145728, $this->frame(0)->measurements->workersMemory);
        self::assertSame(2, $this->status->samples);
        self::assertSame(0.0, $this->get('measurementsRetryAt'));
        self::assertArrayNotHasKey(HilosSignalConstants::DAEMON_MASTER_MEASUREMENTS, $this->get('masterFrameDeliveryProblems'));
        self::assertFalse($this->invoke('hasRunnableMeasurements'));
        $this->loop->tick();
        $this->dispatch();
        self::assertSame(4, $this->manager->measurementAttempts);
    }

    public function testSourceReplacementRejectsStaleCallbacksAndRetainsOnlyRelevantBasis(): void
    {
        $this->dispatch();
        $sampleTimer = $this->get('measurementSampleTimer');
        $escapedSample = $this->timerCue($sampleTimer);
        $escapedReport = $this->timerCue($this->get('measurementReportTimer'));
        $workerWeak = WeakReference::create($this->workers);
        $this->workers = new MeasurementsNativeWorkers();
        $this->set('servers', [$this->workers]);
        self::assertNull($workerWeak->get());
        $escapedSample();
        self::assertFalse($this->get('measurementSampleDue'));
        $this->dispatch();
        self::assertSame(2, $this->status->samples);
        self::assertSame(1, $this->workers->pidReads);
        self::assertNull($sampleTimer->getDeadline());
        $statusWeak = WeakReference::create($this->status);
        $this->status = $this->newStatus();
        $this->set('daemonStatus', $this->status);
        self::assertNull($statusWeak->get());
        $escapedSample();
        $escapedReport();
        self::assertFalse($this->get('measurementSampleDue'));
        self::assertFalse($this->get('measurementReportDue'));
        $this->dispatch();
        self::assertSame(1, $this->status->samples);
        self::assertSame(2, $this->workers->pidReads);
        self::assertNull($this->status->last()->masterCores, 'A new sampler starts with no CPU interval');
        $sampleAt = $this->get('measurementsSampledAt');
        $deadline = $this->get('measurementSampleTimer')->getDeadline();
        putenv(EnvConstants::CLUSTER_ENABLED->name . '=true');
        Hilos::$env = new EnvAccessor();
        $cluster = new ClusterContext();
        $cluster->registerLeadership(new StandaloneLeadership());
        new ReflectionProperty(ClusterContext::class, 'identity')->setValue($cluster, NodeIdentity::of('slave-b', 'B', NodeRole::Slave, []));
        Hilos::$cluster = $cluster;
        $this->dispatch();
        self::assertCount(2, $this->workers->delivered);
        self::assertSame('slave-b', $this->frame(1)->nodeId);
        self::assertSame(1, $this->status->samples, 'Report context replacement does not reset the sampler');
        self::assertSame($sampleAt, $this->get('measurementsSampledAt'));
        self::assertSame($deadline, $this->get('measurementSampleTimer')->getDeadline());
        $this->set('servers', []);
        $escapedSample = $this->timerCue($this->get('measurementSampleTimer'));
        $this->dispatch();
        self::assertSame(2, $this->status->samples);
        self::assertSame(0, $this->status->last()->workersMeasured);
        $this->set('servers', [$this->workers]);
        $escapedSample();
        self::assertFalse($this->get('measurementSampleDue'));
        $this->dispatch();
        self::assertSame(3, $this->status->samples);
        self::assertSame(1, $this->status->last()->workersMeasured);
    }

    public function testLateAgentManagerReplacementWaitsForRealStartedWithoutAnEarlySample(): void
    {
        $this->dispatch();
        $deadline = $this->get('measurementSampleTimer')->getDeadline();
        $escaped = $this->timerCue($this->get('measurementReportTimer'));
        $this->set('agentManagerDaemon', new MeasurementsNativeAgents());
        $escaped();
        self::assertFalse($this->get('measurementReportDue'));
        $this->invoke('forceDaemonNodeFrames');
        $this->dispatch();
        $this->loop->tick();
        self::assertFalse($this->invoke('hasRunnableMeasurements'));
        self::assertFalse($this->loop->hasReadyWork());
        self::assertTrue($this->get('measurementsForce'));
        self::assertNull($this->get('measurementReportTimer')->getDeadline());
        self::assertSame(1, $this->status->samples);
        self::assertCount(1, $this->workers->delivered);
        $this->startNode();
        $this->invoke('reconcileReportFrameSources');
        $this->loop->tick();
        self::assertCount(1, $this->workers->delivered);
        self::assertTrue($this->invoke('hasRunnableMeasurements'));
        $this->dispatch();
        self::assertSame(1, $this->status->samples);
        self::assertSame(1, $this->workers->pidReads);
        self::assertCount(2, $this->workers->delivered);
        self::assertSame($deadline, $this->get('measurementSampleTimer')->getDeadline());
    }

    public function testUnavailableKernelValuesRemainNullInTheLatestStatusAndTypedPayload(): void
    {
        unlink($this->root . '/proc/10/stat');
        unlink($this->root . '/proc/self/status');
        unlink($this->root . '/cgroup/cpu.max');
        $this->loop->tick();
        self::assertSame(0, $this->status->samples);
        $this->dispatch();
        self::assertSame(1, $this->status->samples);
        self::assertSame(1, $this->workers->pidReads);
        self::assertCount(1, $this->workers->delivered);
        $sample = $this->frame(0)->measurements;
        self::assertNull($sample->masterMemory);
        self::assertNull($sample->masterCores);
        self::assertNull($sample->daemonCores);
        self::assertNull($sample->cores);
        self::assertNull($sample->coresBound);
        self::assertSame(2097152, $sample->workersMemory);
        self::assertSame(1, $sample->workersMeasured);
        self::assertSame($sample, $this->manager->daemonStatusSnapshot()->measurements);
        self::assertEquals($this->frame(0), DaemonMasterMeasurementsSignalData::fromArray($this->frame(0)->toArray()));
    }

    public function testActivatedAndQueuedCancellationTerminalExitAndWeakManagerLifetime(): void
    {
        $this->dispatch();
        $sample = $this->get('measurementSampleTimer');
        $report = $this->get('measurementReportTimer');
        $escapedSample = $this->timerCue($sample);
        $escapedReport = $this->timerCue($report);
        $this->ageSampler();
        $this->invoke('forceDaemonNodeFrames');
        $this->loop->createReadyWork(function (): void {
            $this->invoke('detachMeasurementsEventLoop');
        })->markReady();
        $this->loop->tick();
        $escapedSample();
        $escapedReport();
        $this->dispatch();
        self::assertSame(1, $this->status->samples);
        self::assertCount(1, $this->workers->delivered);
        self::assertNull($sample->getDeadline());
        self::assertNull($report->getDeadline());
        self::assertNull($this->get('measurementSampleTimer'));
        self::assertNull($this->get('measurementReportTimer'));
        self::assertFalse($this->invoke('hasRunnableMeasurements'));
        self::assertFalse($this->loop->hasReadyWork());
        $this->invoke('attachMeasurementsEventLoop');
        $this->set('shouldExit', true);
        $this->dispatch();
        $this->loop->tick();
        self::assertSame(1, $this->status->samples);
        self::assertNull($this->get('measurementSampleTimer')->getDeadline());
        self::assertNull($this->get('measurementReportTimer')->getDeadline());
        self::assertFalse($this->invoke('hasRunnableMeasurements'));
        $manager = new ReflectionClass(MeasurementsNativeManager::class)->newInstanceWithoutConstructor();
        foreach (['eventLoop' => $this->loop, 'servers' => [], 'daemonStatus' => $this->status,
            'agentManagerDaemon' => $this->get('agentManagerDaemon'), 'protectedModeSourceBinding' => $this->get('protectedModeSourceBinding')]
            as $name => $value) {
            new ReflectionProperty(DaemonManager::class, $name)->setValue($manager, $value);
        }
        new ReflectionMethod(DaemonManager::class, 'attachMeasurementsEventLoop')->invoke($manager);
        $weak = WeakReference::create($manager);
        unset($manager);
        self::assertNull($weak->get(), 'Native handles do not retain the manager graph');
        $this->loop->tick();
        self::assertSame(1, $this->status->samples);
    }

    protected function setUp(): void
    {
        $this->previousCluster = Hilos::$cluster;
        $this->previousRuntime = Hilos::$rt;
        $this->previousRouter = Hilos::$sr;
        $this->previousEnv = Hilos::$env;
        $this->previousApp = Hilos::appClass();
        $this->previousEnabled = getenv(EnvConstants::CLUSTER_ENABLED->name);
        putenv(EnvConstants::CLUSTER_ENABLED->name . '=false');
        Hilos::$cluster = null;
        Hilos::$rt = null;
        Hilos::$env = new EnvAccessor();
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, MeasurementsNativeHilos::class);
        $this->root = sys_get_temp_dir() . '/hilos-measurements-native-' . bin2hex(random_bytes(8));
        mkdir($this->root);
        $this->logFile = $this->root . '/log';
        Logger::setLogFile($this->logFile);
        $this->put('proc/self/smaps', "KernelPageSize:        4 kB\n");
        $this->put('proc/self/cgroup', "0::/\n");
        $this->put('proc/self/status', "Cpus_allowed_list:\t0-2\n");
        $this->put('proc/meminfo', "MemTotal: 8192 kB\n");
        $this->put('cgroup/cpu.max', "150000 100000\n");
        $this->put('cgroup/memory.max', "4194304\n");
        $this->put('proc/stat', "cpu 100 0 0 100 0 0 0 0\n");
        $this->process(10, 100, 300);
        $this->process(20, 50, 600);
        $this->loop = new EventLoop();
        $this->manager = new MeasurementsNativeManager();
        $this->workers = new MeasurementsNativeWorkers();
        // Mount the socketless PID/transport source without unrelated real-socket attachment closures.
        $this->set('servers', [$this->workers]);
        $this->status = $this->newStatus();
        $this->set('eventLoop', $this->loop);
        $this->set('daemonStatus', $this->status);
        $this->agent = $this->createStub(AgentDaemonInterface::class);
        $this->startNode();
        $this->invoke('attachMeasurementsEventLoop');
    }

    protected function tearDown(): void
    {
        $this->invoke('detachMeasurementsEventLoop');
        $this->invoke('detachReportFramesEventLoop');
        $this->get('protectedModeSourceBinding')->removeWatchdog($this->get('protectedModeWatchdog'));
        $this->loop->cleanup();
        foreach ($this->sockets as $socket) {
            socket_close($socket);
        }
        RtTruthSourceRegistry::unregisterDaemon(ProtectedModeRuntime::RT_ITEM);
        Logger::resetLogFile();
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST,
        ) as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
        Hilos::$cluster = $this->previousCluster;
        Hilos::$rt = $this->previousRuntime;
        Hilos::$sr = $this->previousRouter;
        Hilos::$env = $this->previousEnv;
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->previousApp);
        putenv(EnvConstants::CLUSTER_ENABLED->name . ($this->previousEnabled === false ? '' : '=' . $this->previousEnabled));
        parent::tearDown();
    }

    /** @return MeasurementsNativeStatus Real sampler with a counter around the actual kernel reader work */
    private function newStatus(): MeasurementsNativeStatus
    {
        return new MeasurementsNativeStatus(
            new ProcessMetricsReader($this->root . '/proc', 100), new NodeLimitsReader($this->root . '/proc', $this->root . '/cgroup'), 10, 100.0,
        );
    }

    /** Real Started notification, membership and the existing common force sink. */
    private function startNode(): void
    {
        $agents = $this->get('agentManagerDaemon');
        $agents->addAgent(HilosAgentType::HILOS_DAEMON_NODE, $this->agent, 1, false);
        $agents->handleAgentStarted(new WorkerAgentStartedDTO(HilosAgentType::HILOS_DAEMON_NODE, HilosAgentType::HILOS_DAEMON_NODE, null));
    }

    /** @return MeasurementsNativeRuntime Mounted actual protected source, including the existing revision observer */
    private function runtime(): MeasurementsNativeRuntime
    {
        $runtime = new MeasurementsNativeRuntime();
        Hilos::$rt = $runtime;
        $runtime->mountFeatureItem(ProtectedModeRuntime::RT_ITEM, ProtectedModeRuntime::create());
        RtTruthSourceRegistry::registerDaemon(ProtectedModeRuntime::RT_ITEM);
        $this->get('protectedModeSourceBinding')->registerWatchdog($this->get('protectedModeWatchdog'));
        $this->manager->reconcileProtectedModeSource();
        return $runtime;
    }

    /**
     * Ages both actual interval bases before arming their original now-past sample deadline.
     *
     * @return float Prepared monotonic basis
     */
    private function ageSampler(): float
    {
        $past = hrtime(true) / 1e9 - 5.001;
        $this->set('measurementsSampledAt', $past);
        new ReflectionProperty(DaemonStatus::class, 'previousTime')->setValue($this->status, $past);
        $this->get('measurementSampleTimer')->armAt($past + 5);
        return $past;
    }

    /** Only the actual guarded A5 phase; callbacks and the native base cannot perform it. */
    private function dispatch(): void
    {
        $this->invoke('dispatchMeasurements');
    }

    /** @return ?float Actual CPU denominator basis */
    private function previousSampleTime(): ?float
    {
        return new ReflectionProperty(DaemonStatus::class, 'previousTime')->getValue($this->status);
    }

    /** @return int Exact measurement refusal lines */
    private function refusalLogCount(): int
    {
        return substr_count((string)file_get_contents($this->logFile), HilosSignalConstants::DAEMON_MASTER_MEASUREMENTS);
    }

    /**
     * @param int $index Exact transport position
     * @return DaemonMasterMeasurementsSignalData Original typed whole payload
     */
    private function frame(int $index): DaemonMasterMeasurementsSignalData
    {
        return $this->workers->delivered[$index]->signal->data->data;
    }

    /** One already-readable socket byte lets runOnce return without a production-expiry wait. */
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

    /**
     * @param EventLoopTimer $timer Current or stale native handle
     * @return Closure Escaped production cue
     */
    private function timerCue(EventLoopTimer $timer): Closure
    {
        return new ReflectionProperty(EventLoopTimer::class, 'callback')->getValue($timer);
    }

    /**
     * @param int $pid Kernel identity
     * @param int $ticks CPU ticks
     * @param int $rssPages Resident pages
     */
    private function process(int $pid, int $ticks, int $rssPages): void
    {
        $fields = array_fill(0, 22, '0');
        $fields[0] = 'S';
        $fields[11] = (string)$ticks;
        $fields[12] = '0';
        $fields[19] = '123';
        $fields[21] = (string)$rssPages;
        $this->put('proc/' . $pid . '/stat', $pid . ' (fixture) ' . implode(' ', $fields));
    }

    /**
     * @param string $relative Synthetic pseudo-file
     * @param string $contents Kernel-format bytes
     */
    private function put(string $relative, string $contents): void
    {
        $path = $this->root . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);
    }

    /**
     * @param string $method Actual private production entry
     * @param mixed ...$arguments Entry arguments
     * @return mixed Production result
     */
    private function invoke(string $method, mixed ...$arguments): mixed
    {
        return new ReflectionMethod(DaemonManager::class, $method)->invoke($this->manager, ...$arguments);
    }

    /**
     * @param string $name Observed private basis
     * @return mixed Actual owner state
     */
    private function get(string $name): mixed
    {
        return new ReflectionProperty(DaemonManager::class, $name)->getValue($this->manager);
    }

    /**
     * @param string $name Source or business basis prepared before arming
     * @param mixed $value Exact replacement
     */
    private function set(string $name, mixed $value): void
    {
        new ReflectionProperty(DaemonManager::class, $name)->setValue($this->manager, $value);
    }
}

/** Counts actual parent sampling, including its process and limit readers, without a mock result. */
final class MeasurementsNativeStatus extends DaemonStatus
{
    public int $samples = 0;

    /**
     * @param float $now Actual monotonic sample observation
     * @param list<int> $workerPids Actual PID source result
     * @return DaemonMeasurements Real completed kernel sample
     */
    public function sample(float $now, array $workerPids): DaemonMeasurements
    {
        ++$this->samples;
        return parent::sample($now, $workerPids);
    }
}

final class MeasurementsNativeManager extends DaemonManager
{
    public bool $directFailure = false;
    public ?string $problem = null;
    public int $measurementAttempts = 0;

    /**
     * @param string $agentType Original receiver
     * @param ?string $agentIndex Original receiver index
     * @param string $signalName Addressed report
     * @param SignalDataInterface $data Original typed payload
     */
    public function sendToAgent(string $agentType, ?string $agentIndex, string $signalName, SignalDataInterface $data): void
    {
        if ($signalName === HilosSignalConstants::DAEMON_MASTER_MEASUREMENTS) {
            ++$this->measurementAttempts;
            if ($this->directFailure && $this->problem !== null) {
                throw new LogicException($this->problem);
            }
        }
        parent::sendToAgent($agentType, $agentIndex, $signalName, $data);
    }

    /** @return SignalRouter Empty topology for directly addressed whole measurements */
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    /** @return AgentManagerDaemon Real membership, Started, stopped and force source */
    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new MeasurementsNativeAgents();
    }
}

final class MeasurementsNativeAgents extends AgentManagerDaemon
{
    /**
     * @param string $agentType Unused factory address
     * @param ?string $agentIndex Unused factory index
     * @return AgentDaemonInterface Explicitly supplied agent
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new LogicException('The test supplies each agent instance');
    }
}

final class MeasurementsNativeWorkers extends WorkerServer
{
    public int $pidReads = 0;
    public ?string $problem = null;
    /** @var list<DaemonAgentMessageDTO> Exact transport order */
    public array $delivered = [];

    /** Socketless transport; no child process is started. */
    public function __construct()
    {
    }

    /** @return list<DaemonWorkerPicture> Actual PID list handed to the sampler */
    public function liveWorkerPictures(): array
    {
        ++$this->pidReads;
        return [new DaemonWorkerPicture(1, WorkerConstants::TYPE_REGULAR, 20, null, [])];
    }

    /**
     * @param string $agentType Original receiver
     * @param ?string $agentIndex Original receiver index
     * @param DaemonAgentMessageDTO $messageDto Typed frame delivered through the original sender
     */
    public function sendSignalToAgent(string $agentType, ?string $agentIndex, DaemonAgentMessageDTO $messageDto): void
    {
        if ($this->problem !== null) {
            throw new LogicException($this->problem);
        }
        $this->delivered[] = $messageDto;
    }

    /** @return int Socketless fixture capacity */
    public function getMaxRegularWorkers(): int
    {
        return 0;
    }

    /** No real listener for the captured worker transport. */
    protected function onStart(): void
    {
    }
}

final class MeasurementsNativeRuntime extends RtContext
{
    /** Only explicitly mounted domain state is used. */
    public function configure(): void
    {
    }
}

abstract class MeasurementsNativeHilos extends Hilos
{
    public const array AGENTS = [
        HilosAgentType::HILOS_DAEMON_NODE => [AgentRegistryKey::SCOPE => AgentScope::NODE],
    ];
}
