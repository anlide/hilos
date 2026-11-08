<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Hilos\API\Router\HttpRouter;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Consensus\ClusterConsensusConfig;
use Hilos\Cluster\Consensus\ClusterCoordinator;
use Hilos\Cluster\Consensus\ConsensusMesh;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\DTO\PeerRequestVoteDTO;
use Hilos\Cluster\Peer\DTO\PeerVoteReplyDTO;
use Hilos\Cluster\StandaloneLeadership;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\HttpConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\EventLoop\EventLoopTimer;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Runtime\RtSnapshot;
use Hilos\Runtime\RtSyncApplicator;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Server\CommandServer;
use Hilos\Socket\Server\HttpServer;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentStartedDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Logger;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Socket;
use WeakReference;

/** Real native cues precede the guarded report work; no elapsed-time wait drives these cases. */
final class DaemonManagerReportFramesNativeTest extends TestCase
{
    private EventLoop $loop;
    private ReportFramesNativeManager $manager;
    private ReportFramesNativeWorkers $workers;
    private ReportFramesNativeCommands $commands;
    private ReportFramesNativeRouter $router;
    private HttpServer $http;
    private AgentDaemonInterface $agent;
    private ?ClusterContext $previousCluster;
    private ?RtContext $previousRuntime;
    private ?SignalRouter $previousRouter;
    private ?EnvAccessor $previousEnv;
    private string $previousApp;
    private string|false $previousEnabled;
    private string $logFile;
    /** @var list<Socket> Readable I/O sources, closed after native watches */
    private array $sockets = [];

    /** @return iterable<string, array{string, string}> Independent duty and its output contract */
    public static function duties(): iterable
    {
        yield 'cron' => ['cronFrame', HilosSignalConstants::DAEMON_MASTER_CRON];
        yield 'standing' => ['standing', HilosSignalConstants::DAEMON_MASTER_STANDING];
        yield 'http' => ['httpFrame', HilosSignalConstants::DAEMON_MASTER_HTTP];
    }

    #[DataProvider('duties')]
    public function testAgedRepairBeforeBindCuesOnlyThenSendsExactlyOnce(string $duty, string $signal): void
    {
        $this->reports();
        $past = hrtime(true) / 1e9 - 61;
        $this->set($duty . 'LastSentAt', $past);
        if ($duty !== 'cronFrame') {
            $this->set($duty . 'SampledAt', $past);
        }
        $this->invoke('attachReportFramesEventLoop');
        $this->loop->tick();
        self::assertCount(3, $this->workers->delivered, 'Native expiry never samples or sends a frame');
        self::assertTrue($this->invoke('hasRunnableReportFrames'));
        $this->dispatch($duty);
        self::assertSame([
            HilosSignalConstants::DAEMON_MASTER_CRON,
            HilosSignalConstants::DAEMON_MASTER_STANDING,
            HilosSignalConstants::DAEMON_MASTER_HTTP,
            $signal,
        ], $this->names());
        $this->loop->tick();
        $this->dispatch($duty);
        self::assertCount(4, $this->workers->delivered);
        self::assertFalse($this->invoke('hasRunnableReportFrames'));
        self::assertFalse($this->loop->hasReadyWork());
    }

    #[DataProvider('duties')]
    public function testActualNativeTimerOnlyCuesTheGuardFromAnOriginalAgedBasis(string $duty, string $signal): void
    {
        $this->reports();
        $past = hrtime(true) / 1e9 - 61;
        $this->set($duty . 'LastSentAt', $past);
        if ($duty !== 'cronFrame') {
            $this->set($duty . 'SampledAt', $past);
        }
        // Age the real business basis before arming that exact deadline on its actual native handle.
        $this->get($duty . 'Timer')->armAt($past + 60);
        self::assertFalse($this->get($duty . 'Due'));
        $this->loop->tick();
        self::assertTrue($this->get($duty . 'Due'), 'The actual EventLoop timer invokes the production cue');
        self::assertCount(3, $this->workers->delivered);
        $this->dispatch($duty);
        self::assertCount(4, $this->workers->delivered);
        self::assertSame($signal, $this->names()[3]);
        self::assertFalse($this->get($duty . 'Due'));
        self::assertGreaterThan(hrtime(true) / 1e9, $this->get($duty . 'Timer')->getDeadline());
    }

    #[DataProvider('duties')]
    public function testFutureDeadlineAndEqualArmSurviveReadableIoAndSameSourceRebind(string $duty, string $signal): void
    {
        $this->reports();
        self::assertContains($signal, $this->names());
        $timer = $this->get($duty . 'Timer');
        $deadline = $timer->getDeadline();
        $generation = new ReflectionProperty(EventLoopTimer::class, 'generation')->getValue($timer);
        $sampled = $duty === 'cronFrame' ? null : $this->get($duty . 'SampledAt');
        $rowReads = $this->router->rowReads;
        for ($pass = 0; $pass < 3; ++$pass) {
            $this->readableWake();
            $this->reports();
            self::assertSame($deadline, $timer->getDeadline());
            self::assertSame($generation, new ReflectionProperty(EventLoopTimer::class, 'generation')->getValue($timer));
        }
        if ($duty !== 'cronFrame') {
            self::assertSame($sampled, $this->get($duty . 'SampledAt'));
        }
        self::assertSame($rowReads, $this->router->rowReads, 'Stable reconcile reads no route rows');
        $escaped = $this->timerCallback($timer);
        $this->invoke('attachReportFramesEventLoop');
        self::assertSame($deadline, $this->get($duty . 'Timer')->getDeadline());
        self::assertNull($timer->getDeadline());
        $escaped();
        self::assertFalse($this->get($duty . 'Due'));
        self::assertCount(3, $this->workers->delivered);
    }

    public function testUnchangedStandingAndHttpSamplesAdvanceWithoutDuplicateFrames(): void
    {
        $this->reports();
        $past = hrtime(true) / 1e9 - 2;
        $this->set('standingSampledAt', $past);
        $this->set('httpFrameSampledAt', $past);
        $rowReads = $this->router->rowReads;
        $this->invoke('attachReportFramesEventLoop');
        $this->loop->tick();
        self::assertSame($past, $this->get('standingSampledAt'));
        $this->reports();
        self::assertGreaterThan($past, $this->get('standingSampledAt'));
        self::assertGreaterThan($past, $this->get('httpFrameSampledAt'));
        self::assertSame($rowReads, $this->router->rowReads);
        self::assertCount(3, $this->workers->delivered);
        $this->commands->port(4567);
        $this->set('standingSampledAt', $past);
        $this->invoke('reconcileReportFrameSources');
        $this->loop->tick();
        $this->dispatch('standing');
        self::assertCount(4, $this->workers->delivered);
        self::assertSame(4567, $this->data(3)->standing->commandPort);
    }

    public function testStandingBuildFailureRetainsPictureAndOriginalRetryUntilForceOrRepair(): void
    {
        $this->reports();
        $old = $this->get('standingLastSent');
        $this->commands->port(65536);
        $this->set('standingSampledAt', hrtime(true) / 1e9 - 2);
        $this->dispatch('standing');
        $retry = $this->get('standingRetryAt');
        self::assertSame($this->get('standingLastSentAt') + 60, $retry);
        self::assertSame($old, $this->get('standingLastSent'));
        self::assertCount(3, $this->workers->delivered);
        $this->commands->port(4567);
        $this->readableWake();
        $this->dispatch('standing');
        self::assertSame($retry, $this->get('standingTimer')->getDeadline());
        self::assertSame($retry, $this->get('standingRetryAt'));
        self::assertCount(3, $this->workers->delivered);
        $this->manager->onAgentStarted(HilosAgentType::HILOS_DAEMON_NODE);
        $this->loop->tick();
        self::assertCount(3, $this->workers->delivered);
        $this->reports();
        self::assertCount(6, $this->workers->delivered);
        self::assertSame(4567, $this->data(4)->standing->commandPort);
        self::assertSame(0.0, $this->get('standingRetryAt'));
        self::assertSame(0.0, $this->get('measurementsSampledAt'), 'Frame force does not sample measurements');
        $this->commands->port(65536);
        $this->set('standingSampledAt', hrtime(true) / 1e9 - 2);
        $this->dispatch('standing');
        $this->commands->port(9000);
        $past = hrtime(true) / 1e9 - 61;
        $this->set('standingLastSentAt', $past);
        $this->set('standingSampledAt', $past);
        $this->set('standingRetryAt', $past + 60);
        $this->invoke('attachReportFramesEventLoop');
        $this->loop->tick();
        self::assertCount(6, $this->workers->delivered);
        $this->dispatch('standing');
        self::assertCount(7, $this->workers->delivered);
        self::assertSame(9000, $this->data(6)->standing->commandPort);
    }

    public function testHttpTrafficAndEpochHourAgeWithoutAnotherTrafficRevision(): void
    {
        $hour = intdiv(time(), TimeConstants::SECONDS_PER_HOUR) * TimeConstants::SECONDS_PER_HOUR;
        $this->router->traffic()->record(HttpConstants::METHOD_GET, '/a', 503, 1001, $hour - 24 * TimeConstants::SECONDS_PER_HOUR);
        $past = hrtime(true) / 1e9 - 2;
        $this->invoke('tickHttpFrame', $past, $hour - TimeConstants::SECONDS_PER_HOUR);
        self::assertCount(1, $this->workers->delivered);
        self::assertSame(1, $this->data(0)->http->routes[0]->tally->requests);
        self::assertSame(1, $this->data(0)->http->routes[0]->tally->serverErrors);
        self::assertSame(1, $this->data(0)->http->routes[0]->tally->slow);
        self::assertSame(1001, $this->data(0)->http->routes[0]->tally->slowestMs);
        $revision = $this->router->traffic()->revision();
        $this->invoke('attachReportFramesEventLoop');
        $this->loop->tick();
        self::assertCount(1, $this->workers->delivered);
        $this->dispatch('httpFrame');
        self::assertCount(2, $this->workers->delivered);
        self::assertSame($revision, $this->router->traffic()->revision());
        self::assertSame(0, $this->data(1)->http->routes[0]->tally->requests);
        self::assertNull($this->data(1)->http->routes[0]->tally->slowestMs);
        $this->router->traffic()->record(HttpConstants::METHOD_GET, '/a', null, 40, time());
        $this->router->traffic()->recordUnrouted(500, 2000, time());
        $this->set('httpFrameSampledAt', $past);
        $this->invoke('reconcileReportFrameSources');
        $this->loop->tick();
        self::assertCount(2, $this->workers->delivered);
        $this->dispatch('httpFrame');
        self::assertCount(3, $this->workers->delivered);
        self::assertSame(1, $this->data(2)->http->routes[0]->tally->requests);
        self::assertSame(0, $this->data(2)->http->routes[0]->tally->serverErrors);
        self::assertSame(1, $this->data(2)->http->unrouted->serverErrors);
        self::assertSame(1, $this->data(2)->http->unrouted->slow);
        self::assertSame(['GET /a', 'POST /a', 'GET /z'], array_map(
            static fn ($row): string => $row->method . ' ' . $row->path, $this->data(2)->http->routes,
        ));
        self::assertSame('fixture_agent', $this->data(2)->http->routes[2]->agentType);
    }

    public function testHttpContainedDeliveryRefusalKeepsRetryAndChangedReasonLogging(): void
    {
        $this->reports();
        $this->workers->problem = 'first refusal';
        $this->manager->onAgentStarted(HilosAgentType::HILOS_DAEMON_NODE);
        $this->dispatch('httpFrame');
        self::assertCount(3, $this->workers->delivered);
        self::assertSame(4, $this->workers->attempts);
        $retry = $this->get('httpFrameLastSentAt') + 60;
        $this->router->traffic()->recordUnrouted(503, 99, time());
        $this->readableWake();
        $this->dispatch('httpFrame');
        self::assertSame($retry, $this->get('httpFrameTimer')->getDeadline());
        self::assertSame(4, $this->workers->attempts);
        $log = (string)file_get_contents($this->logFile);
        $this->manager->onAgentStarted(HilosAgentType::HILOS_DAEMON_NODE);
        $this->dispatch('httpFrame');
        self::assertSame($log, file_get_contents($this->logFile), 'An identical contained refusal is silent');
        $this->workers->problem = 'second refusal';
        $this->manager->onAgentStarted(HilosAgentType::HILOS_DAEMON_NODE);
        $this->dispatch('httpFrame');
        self::assertStringContainsString('second refusal', (string)file_get_contents($this->logFile));
        $this->workers->problem = null;
        $past = hrtime(true) / 1e9 - 61;
        $this->set('httpFrameLastSentAt', $past);
        $this->set('httpFrameSampledAt', $past);
        $this->invoke('attachReportFramesEventLoop');
        $this->loop->tick();
        self::assertCount(3, $this->workers->delivered);
        $this->dispatch('httpFrame');
        self::assertCount(4, $this->workers->delivered);
        self::assertArrayNotHasKey(HilosSignalConstants::DAEMON_MASTER_HTTP, $this->get('masterFrameDeliveryProblems'));
    }

    public function testHttpBuildFailureIsContainedSeparatelyFromDelivery(): void
    {
        $this->reports();
        $old = $this->get('httpFrameLastSent');
        $this->router->failRows = true;
        $this->router->traffic()->recordUnrouted(503, 1, time());
        $this->set('httpFrameSampledAt', hrtime(true) / 1e9 - 2);
        $this->dispatch('httpFrame');
        self::assertSame(3, $this->workers->attempts, 'A row-build failure never reaches the sender');
        self::assertSame($old, $this->get('httpFrameLastSent'));
        $retry = $this->get('httpFrameLastSentAt') + 60;
        $this->router->failRows = false;
        $this->readableWake();
        $this->dispatch('httpFrame');
        self::assertSame($retry, $this->get('httpFrameTimer')->getDeadline());
        $this->manager->onAgentStarted(HilosAgentType::HILOS_DAEMON_NODE);
        $this->dispatch('httpFrame');
        self::assertCount(4, $this->workers->delivered);
        self::assertSame(1, $this->data(3)->http->unrouted->requests);
    }

    public function testCronMutationsFireAndRoleLossCueWithoutTakingTheMinuteTimer(): void
    {
        $this->set('workersReady', true);
        $this->manager->addRule('z', '* * * * *');
        $this->manager->addRule('a', '*/7 * * * *');
        $this->invoke('attachCronEventLoop');
        $minute = $this->get('cronMinuteTimer')->getDeadline();
        $this->reports();
        self::assertSame(['a', 'z'], array_map(static fn ($rule): string => $rule->name, $this->data(0)->rules));
        $this->manager->changeRule('a', '*/9 * * * *');
        self::assertTrue($this->invoke('hasRunnableReportFrames'), 'The producer directly cues the report');
        $this->loop->tick();
        self::assertCount(3, $this->workers->delivered);
        $this->dispatch('cronFrame');
        self::assertSame('*/9 * * * *', $this->data(3)->rules[0]->expression);
        $this->manager->removeRule('a');
        $this->dispatch('cronFrame');
        self::assertSame(['z'], array_map(static fn ($rule): string => $rule->name, $this->data(4)->rules));
        self::assertSame($minute, $this->get('cronMinuteTimer')->getDeadline());
        $rule = $this->manager->getCronRules()['z'];
        $rule->pauseSchedule('native-check');
        $this->invoke('checkCronJobs');
        self::assertNull($rule->lastFiredAt);
        $reply = $this->invoke('fireDaemonCronRuleForTest', 'native-fire', 'z');
        self::assertTrue($reply->isOk());
        self::assertSame($rule->lastFiredAt * 1000, $reply->payload[CommandConstants::FIELD_FIRED_AT]);
        $this->loop->tick();
        self::assertCount(5, $this->workers->delivered);
        $this->dispatch('cronFrame');
        self::assertSame($rule->lastFiredAt * 1000, $this->data(5)->rules[0]->lastRunAt);
        self::assertGreaterThan(time() - 2, $rule->lastFiredAt);
        self::assertSame($minute, $this->get('cronMinuteTimer')->getDeadline());
        $rule->resumeSchedule('native-check');
        self::assertFalse($this->get('cronFrameDirty'));
        $mesh = $this->createStub(ConsensusMesh::class);
        $mesh->method('onlineMasterIds')->willReturn(['a', 'b', 'c']);
        $coordinator = new ClusterCoordinator(
            new ClusterConsensusConfig('a', ['a', 'b', 'c'], 2, 1000, 1000, 100), $mesh, $this->manager,
        );
        $cluster = new ClusterContext();
        $cluster->registerLeadership($coordinator);
        new ReflectionProperty(ClusterContext::class, 'identity')->setValue($cluster, NodeIdentity::of('a', 'A', NodeRole::Master, []));
        Hilos::$cluster = $cluster;
        $coordinator->tick(0.0);
        $coordinator->tick(1.0);
        $coordinator->onVoteReply(new PeerVoteReplyDTO(1, true, 'b'));
        $this->reports();
        self::assertNull($this->data(6)->idleReason);
        $before = count($this->workers->delivered);
        $coordinator->onRequestVote(new PeerRequestVoteDTO(2, 'b'));
        self::assertTrue($this->manager->leaderDuringLoss);
        self::assertFalse($coordinator->amLeader());
        self::assertCount($before, $this->workers->delivered, 'The role observer never dispatches report business');
        $this->invoke('reconcileReportFrameSources');
        $this->loop->tick();
        $this->dispatch('cronFrame');
        self::assertSame('not_leader', $this->data($before)->idleReason);
    }

    public function testLeadershipReplacementInvalidatesOnlyCronAndStandingBasis(): void
    {
        $cluster = new ClusterContext();
        $cluster->registerLeadership(new StandaloneLeadership());
        Hilos::$cluster = $cluster;
        $this->reports();
        self::assertCount(3, $this->workers->delivered);
        $httpTimer = $this->get('httpFrameTimer');
        $httpDeadline = $httpTimer->getDeadline();
        $httpSample = $this->get('httpFrameSampledAt');
        $escaped = $this->timerCallback($this->get('cronFrameTimer'));
        $cluster->registerLeadership(new StandaloneLeadership());
        $escaped();
        self::assertFalse($this->get('cronFrameDue'));
        $this->invoke('reconcileReportFrameSources');
        $this->loop->tick();
        self::assertCount(3, $this->workers->delivered);
        $this->reports();
        self::assertSame([
            HilosSignalConstants::DAEMON_MASTER_CRON,
            HilosSignalConstants::DAEMON_MASTER_STANDING,
            HilosSignalConstants::DAEMON_MASTER_HTTP,
            HilosSignalConstants::DAEMON_MASTER_CRON,
            HilosSignalConstants::DAEMON_MASTER_STANDING,
        ], $this->names());
        self::assertSame($httpTimer, $this->get('httpFrameTimer'));
        self::assertSame($httpDeadline, $httpTimer->getDeadline());
        self::assertSame($httpSample, $this->get('httpFrameSampledAt'));
        $escaped();
        self::assertFalse($this->get('cronFrameDue'));
    }

    public function testCronAttemptAndBuildCatchRetainRepairButNewDirtyBypassesIt(): void
    {
        $this->reports();
        $this->manager->addRule('job', '* * * * *');
        $this->workers->problem = 'cron delivery refused';
        $this->loop->tick();
        self::assertSame(3, $this->workers->attempts);
        $this->dispatch('cronFrame');
        self::assertSame(4, $this->workers->attempts);
        self::assertCount(3, $this->workers->delivered);
        $repair = $this->get('cronFrameLastSentAt') + 60;
        self::assertFalse($this->get('cronFrameDirty'));
        self::assertFalse($this->get('cronFrameForce'));
        $this->workers->problem = null;
        $this->readableWake();
        $this->dispatch('cronFrame');
        self::assertSame($repair, $this->get('cronFrameTimer')->getDeadline());
        self::assertSame(4, $this->workers->attempts);
        $rule = $this->manager->getCronRules()['job'];
        $malformed = new ReflectionClass(CronRule::class)->newInstanceWithoutConstructor();
        new ReflectionProperty(CronRule::class, 'name')->setValue($malformed, '');
        $this->set('cronRules', ['job' => $malformed]);
        $this->manager->changeRule('job', '*/9 * * * *');
        $this->loop->tick();
        $this->dispatch('cronFrame');
        self::assertSame(4, $this->workers->attempts, 'The cron row-build catch never reaches the sender');
        self::assertFalse($this->get('cronFrameDirty'));
        self::assertFalse($this->get('cronFrameForce'));
        self::assertSame($this->get('cronFrameLastSentAt') + 60, $this->get('cronFrameTimer')->getDeadline());
        $this->set('cronRules', ['job' => $rule]);
        $this->manager->changeRule('job', '*/13 * * * *');
        $this->loop->tick();
        $this->dispatch('cronFrame');
        self::assertCount(4, $this->workers->delivered);
        self::assertSame('*/13 * * * *', $this->data(3)->rules[0]->expression);
        self::assertArrayNotHasKey(HilosSignalConstants::DAEMON_MASTER_CRON, $this->get('masterFrameDeliveryProblems'));
    }

    #[DataProvider('duties')]
    public function testLateRuntimeReplacementMakesEscapedCallbackInertBeforeReconcile(string $duty, string $signal): void
    {
        $this->reports();
        self::assertContains($signal, $this->names());
        $escaped = $this->timerCallback($this->get($duty . 'Timer'));
        Hilos::$rt = new ReportFramesNativeRuntime();
        $escaped();
        self::assertFalse($this->get($duty . 'Due'));
        self::assertFalse($this->loop->hasReadyWork());
        $this->invoke('reconcileReportFrameSources');
        self::assertTrue($this->invoke('hasRunnableReportFrames'));
        $this->loop->tick();
        self::assertCount(3, $this->workers->delivered);
        $this->reports();
        self::assertCount(6, $this->workers->delivered);
        $escaped();
        self::assertFalse($this->get($duty . 'Due'));
        self::assertFalse($this->invoke('hasRunnableReportFrames'));
    }

    public function testFreezeAndNoAgentForceAreDormantAndActualActionsLiftResumesAllThree(): void
    {
        $runtime = $this->runtime();
        $this->reports();
        $runtime->hilosProtectedModeRuntime->actions->enterActive();
        $this->invoke('forceDaemonNodeFrames');
        $this->invoke('reconcileReportFrameSources');
        $this->loop->tick();
        $this->reports();
        self::assertCount(3, $this->workers->delivered);
        self::assertFalse($this->invoke('hasRunnableReportFrames'));
        self::assertFalse($this->loop->hasReadyWork());
        foreach (['cronFrame', 'standing', 'httpFrame'] as $duty) {
            self::assertTrue($this->get($duty . 'Force'));
            self::assertNull($this->get($duty . 'Timer')->getDeadline());
        }
        $runtime->hilosProtectedModeRuntime->actions->enterInactive();
        $this->invoke('reconcileReportFrameSources');
        self::assertTrue($this->invoke('hasRunnableReportFrames'));
        $this->loop->tick();
        self::assertCount(3, $this->workers->delivered);
        $this->reports();
        self::assertCount(6, $this->workers->delivered);
        $agents = $this->get('agentManagerDaemon');
        $agents->removeAgent(HilosAgentType::HILOS_DAEMON_NODE);
        $this->invoke('forceDaemonNodeFrames');
        $this->reports();
        $this->loop->tick();
        self::assertFalse($this->invoke('hasRunnableReportFrames'));
        self::assertFalse($this->loop->hasReadyWork());
        self::assertCount(6, $this->workers->delivered);
        $this->startNode();
        $this->invoke('reconcileReportFrameSources');
        $this->loop->tick();
        $this->reports();
        self::assertCount(9, $this->workers->delivered);
        self::assertSame(0.0, $this->get('measurementsSampledAt'));
    }

    public function testTransientProtectedFactAndSyncRemountOrNullEachResumeOneNeededPass(): void
    {
        $runtime = $this->runtime();
        $this->reports();
        $runtime->hilosProtectedModeRuntime->actions->enterActive();
        $runtime->hilosProtectedModeRuntime->actions->enterInactive();
        self::assertFalse($this->loop->hasReadyWork(), 'The shared protected observer remains the sole observer');
        $this->invoke('reconcileReportFrameSources');
        self::assertTrue($this->invoke('hasRunnableReportFrames'));
        $this->loop->tick();
        $this->reports();
        self::assertCount(6, $this->workers->delivered);
        RtSyncApplicator::applyUpdated(new RtSyncUpdatedSignalData(ProtectedModeRuntime::RT_ITEM, ProtectedModeRuntime::ID, [
            ProtectedModeRuntime::phase => ProtectedModeRuntime::PHASE_ACTIVE,
        ]));
        $this->reports();
        $this->loop->tick();
        self::assertFalse($this->invoke('hasRunnableReportFrames'));
        self::assertCount(6, $this->workers->delivered);
        RtSnapshot::replace(ProtectedModeRuntime::RT_ITEM, [ProtectedModeRuntime::ID => ProtectedModeRuntime::create()->toArray()]);
        $this->manager->reconcileProtectedModeSource();
        $this->reports();
        self::assertCount(9, $this->workers->delivered);
        Hilos::$rt = null;
        $this->reports();
        self::assertCount(12, $this->workers->delivered);
        $runtime = $this->runtime();
        $this->reports();
        self::assertCount(15, $this->workers->delivered);
        self::assertSame(ProtectedModeRuntime::PHASE_INACTIVE, $runtime->hilosProtectedModeRuntime->phase);
    }

    public function testAbsentHttpAndReplacedSourcesCancelOldCallbacksWithoutRetainingGraph(): void
    {
        $this->reports();
        $old = $this->get('httpFrameTimer');
        $escaped = $this->timerCallback($old);
        $this->set('servers', [$this->workers, $this->commands]);
        $this->set('httpRouter', null);
        $escaped();
        self::assertFalse($this->get('httpFrameDue'), 'An old source cannot cue even before guarded reconciliation');
        $this->invoke('forceDaemonNodeFrames');
        $this->reports();
        $this->loop->tick();
        self::assertCount(5, $this->workers->delivered);
        self::assertTrue($this->get('httpFrameForce'));
        self::assertFalse($this->get('httpFrameDue'));
        self::assertFalse($this->invoke('hasRunnableReportFrames'));
        self::assertFalse($this->loop->hasReadyWork());
        $escaped();
        self::assertFalse($this->get('httpFrameDue'));
        $weak = WeakReference::create($this->router);
        $this->router = new ReportFramesNativeRouter();
        $this->set('httpRouter', $this->router);
        $this->manager->registerServer($this->http);
        self::assertNull($weak->get(), 'The binding does not retain an obsolete router graph');
        $this->invoke('reconcileReportFrameSources');
        $this->loop->tick();
        self::assertCount(5, $this->workers->delivered);
        $this->dispatch('httpFrame');
        self::assertCount(6, $this->workers->delivered);
        self::assertSame([], $this->data(5)->http->routes);
        $escaped();
        self::assertFalse($this->get('httpFrameDue'));
        self::assertNull($old->getDeadline());
        $this->commands = new ReportFramesNativeCommands();
        $this->commands->port(7000);
        $this->set('servers', [$this->workers, $this->commands, $this->http]);
        $this->reports();
        self::assertCount(7, $this->workers->delivered);
        self::assertSame(7000, $this->data(6)->standing->commandPort);
    }

    #[DataProvider('duties')]
    public function testActivatedDetachAndStaleCallbacksCannotReviveBusiness(string $duty, string $signal): void
    {
        $this->reports();
        self::assertContains($signal, $this->names());
        $timer = $this->get($duty . 'Timer');
        $escaped = $this->timerCallback($timer);
        $this->invoke('forceDaemonNodeFrames');
        $this->loop->createReadyWork(function (): void {
            $this->invoke('detachReportFramesEventLoop');
        })->markReady();
        $this->loop->tick();
        $escaped();
        $this->reports();
        self::assertFalse($this->get($duty . 'Due'));
        self::assertFalse($this->invoke('hasRunnableReportFrames'));
        self::assertFalse($this->loop->hasReadyWork());
        self::assertNull($timer->getDeadline());
        self::assertCount(3, $this->workers->delivered);
        self::assertNull($this->get($duty . 'Timer'));
    }

    public function testNativeHandlesHoldOnlyWeakManagerAndExitCancelsDue(): void
    {
        $this->reports();
        $manager = new ReflectionClass(ReportFramesNativeManager::class)->newInstanceWithoutConstructor();
        foreach (['eventLoop' => $this->loop, 'servers' => [], 'agentManagerDaemon' => $this->get('agentManagerDaemon'),
            'protectedModeSourceBinding' => $this->get('protectedModeSourceBinding')] as $name => $value) {
            new ReflectionProperty(DaemonManager::class, $name)->setValue($manager, $value);
        }
        new ReflectionMethod(DaemonManager::class, 'attachReportFramesEventLoop')->invoke($manager);
        $weak = WeakReference::create($manager);
        unset($manager);
        self::assertNull($weak->get());
        $this->loop->tick();
        self::assertCount(3, $this->workers->delivered);
        $this->set('shouldExit', true);
        $this->invoke('forceDaemonNodeFrames');
        $this->reports();
        $this->loop->tick();
        self::assertFalse($this->invoke('hasRunnableReportFrames'));
        self::assertFalse($this->loop->hasReadyWork());
        self::assertCount(3, $this->workers->delivered);
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
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, ReportFramesNativeHilos::class);
        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-report-native-');
        Logger::setLogFile($this->logFile);
        $this->loop = new EventLoop();
        $this->manager = new ReportFramesNativeManager();
        $this->workers = new ReportFramesNativeWorkers();
        $this->commands = new ReportFramesNativeCommands();
        $this->http = new HttpServer('127.0.0.1', 8080);
        $this->router = new ReportFramesNativeRouter();
        $this->router->addRoute(HttpConstants::METHOD_POST, '/a', static fn (): array => []);
        $this->router->addRoute(HttpConstants::METHOD_GET, '/a', static fn (): array => []);
        $this->router->addAgentRoute(HttpConstants::METHOD_GET, '/z', 'fixture_agent');
        new ReflectionProperty(WorkerServer::class, 'agentManager')->setValue($this->workers, $this->get('agentManagerDaemon'));
        $this->manager->registerServer($this->workers);
        $this->manager->registerServer($this->commands);
        $this->manager->registerServer($this->http);
        $this->set('httpRouter', $this->router);
        $this->set('eventLoop', $this->loop);
        $this->agent = $this->createStub(AgentDaemonInterface::class);
        $this->startNode();
        $this->invoke('attachReportFramesEventLoop');
    }

    protected function tearDown(): void
    {
        $this->invoke('detachReportFramesEventLoop');
        $this->invoke('detachCronEventLoop');
        $this->get('protectedModeSourceBinding')->removeWatchdog($this->get('protectedModeWatchdog'));
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
        parent::tearDown();
    }

    /** Real membership, actual Started and the existing force sink. */
    private function startNode(): void
    {
        $agents = $this->get('agentManagerDaemon');
        $agents->addAgent(HilosAgentType::HILOS_DAEMON_NODE, $this->agent, 1, false);
        $agents->handleAgentStarted(new WorkerAgentStartedDTO(HilosAgentType::HILOS_DAEMON_NODE, HilosAgentType::HILOS_DAEMON_NODE, null));
    }

    /** @return ReportFramesNativeRuntime Mounted domain state with the actual protected source compositor */
    private function runtime(): ReportFramesNativeRuntime
    {
        $runtime = new ReportFramesNativeRuntime();
        Hilos::$rt = $runtime;
        $runtime->mountFeatureItem(ProtectedModeRuntime::RT_ITEM, ProtectedModeRuntime::create());
        RtTruthSourceRegistry::registerDaemon(ProtectedModeRuntime::RT_ITEM);
        $this->get('protectedModeSourceBinding')->registerWatchdog($this->get('protectedModeWatchdog'));
        $this->manager->reconcileProtectedModeSource();
        return $runtime;
    }

    /** Only these guarded duties, no blanket manager pass or manual legacy reporting tick. */
    private function reports(): void
    {
        $this->dispatch('cronFrame');
        $this->dispatch('standing');
        $this->dispatch('httpFrame');
    }

    /** @param string $duty Independent guarded report phase */
    private function dispatch(string $duty): void
    {
        $this->invoke('dispatch' . ucfirst($duty));
    }

    /** @return list<string> Exact transport output, never filtered */
    private function names(): array
    {
        return array_map(static fn (DaemonAgentMessageDTO $message): string => $message->signal->signalName->getName(), $this->workers->delivered);
    }

    /**
     * @param int $index Exact delivery position
     * @return object Typed original frame DTO
     */
    private function data(int $index): object
    {
        return $this->workers->delivered[$index]->signal->data->data;
    }

    /** One readable byte makes runOnce return without waiting out a future timer. */
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
     * @param EventLoopTimer $timer Old native binding
     * @return Closure Escaped callback to exercise generation rejection
     */
    private function timerCallback(EventLoopTimer $timer): Closure
    {
        return new ReflectionProperty(EventLoopTimer::class, 'callback')->getValue($timer);
    }

    /**
     * @param string $method Local production contract
     * @param mixed ...$arguments Contract arguments
     * @return mixed Contract result
     */
    private function invoke(string $method, mixed ...$arguments): mixed
    {
        return new ReflectionMethod(DaemonManager::class, $method)->invoke($this->manager, ...$arguments);
    }

    /**
     * @param string $name Current private owner state
     * @return mixed Observed basis
     */
    private function get(string $name): mixed
    {
        return new ReflectionProperty(DaemonManager::class, $name)->getValue($this->manager);
    }

    /**
     * @param string $name Original business basis prepared before binding
     * @param mixed $value Basis or current source
     */
    private function set(string $name, mixed $value): void
    {
        new ReflectionProperty(DaemonManager::class, $name)->setValue($this->manager, $value);
    }
}

/** No worker processes: the real manager still owns membership and report delivery. */
final class ReportFramesNativeManager extends DaemonManager
{
    public bool $leaderDuringLoss = false;

    /**
     * @param string $name Project rule name
     * @param string $expression Registered schedule
     */
    public function addRule(string $name, string $expression): void
    {
        $this->addCronRule($name, $expression);
    }

    /**
     * @param string $name Project rule name
     * @param string $expression Replacement schedule
     */
    public function changeRule(string $name, string $expression): void
    {
        $this->updateCronRule($name, $expression);
    }

    /** @param string $name Project rule name */
    public function removeRule(string $name): void
    {
        $this->removeCronRule($name);
    }

    /** @param int $term Actual observer term before the coordinator swaps role */
    public function onLostLeadership(int $term): void
    {
        $this->leaderDuringLoss = Hilos::$cluster->amLeader();
        parent::onLostLeadership($term);
    }

    /** @param CronRule $rule Actual firing recorded by the production scheduler */
    protected function onCron(CronRule $rule): void
    {
    }

    /** @return SignalRouter Empty topology for directly addressed reports */
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    /** @return AgentManagerDaemon Actual membership and Started producer */
    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new ReportFramesNativeAgents();
    }
}

final class ReportFramesNativeAgents extends AgentManagerDaemon
{
    /**
     * @param string $agentType Unused factory type
     * @param ?string $agentIndex Unused factory index
     * @return AgentDaemonInterface No worker factory runs here
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new LogicException('The test supplies each agent instance');
    }
}

final class ReportFramesNativeWorkers extends WorkerServer
{
    /** @var list<DaemonAgentMessageDTO> All addressed output in transport order */
    public array $delivered = [];
    public int $attempts = 0;
    public ?string $problem = null;

    public function __construct()
    {
    }

    /**
     * @param string $agentType Receiver type
     * @param ?string $agentIndex Receiver index
     * @param DaemonAgentMessageDTO $messageDto Exact addressed frame
     */
    public function sendSignalToAgent(string $agentType, ?string $agentIndex, DaemonAgentMessageDTO $messageDto): void
    {
        ++$this->attempts;
        if ($this->problem !== null) {
            throw new LogicException($this->problem);
        }
        $this->delivered[] = $messageDto;
    }

    protected function onStart(): void
    {
    }
}

final class ReportFramesNativeCommands extends CommandServer
{
    public function __construct()
    {
        $this->port = 0;
    }

    /** @param int $port Registered listener value or malformed value for the sample failure case */
    public function port(int $port): void
    {
        $this->port = $port;
    }
}

final class ReportFramesNativeRouter extends HttpRouter
{
    public int $rowReads = 0;
    public bool $failRows = false;

    /**
     * @param string $method Registered method
     * @param string $path Registered route template
     * @return ?string Production agent address
     */
    public function agentTypeAt(string $method, string $path): ?string
    {
        ++$this->rowReads;
        if ($this->failRows) {
            throw new LogicException('Route row cannot be built');
        }
        return parent::agentTypeAt($method, $path);
    }
}

final class ReportFramesNativeRuntime extends RtContext
{
    public function configure(): void
    {
    }
}

abstract class ReportFramesNativeHilos extends Hilos
{
    public const array AGENTS = [
        HilosAgentType::HILOS_DAEMON_NODE => [AgentRegistryKey::SCOPE => AgentScope::NODE],
    ];
}
