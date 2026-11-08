<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\PendingLeadership;
use Hilos\Cluster\StandaloneLeadership;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\ProtectedMode\ProtectedModeSourceBinding;
use Hilos\ProtectedMode\ProtectedModeWatchdog;
use Hilos\Runtime\RtSyncApplicator;
use Hilos\Runtime\RtSnapshot;
use Hilos\Socket\Server\WorkerServer;
use ReflectionClass;
use ReflectionProperty;
use WeakReference;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Sync\DTO\RtSyncSignalDataInterface;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Mail\DTO\MailSendSignalData;
use Hilos\Mail\EmailMessage;
use Hilos\Mail\HilosMailer;
use Hilos\ProtectedMode\DTO\ProtectedModeWatchdogSnapshot;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/** The master's bounded watchdog publication and its existing singleton-duties gate. */
final class DaemonManagerProtectedModeWatchdogTest extends TestCase
{
    private ?RtContext $previousRuntime = null;

    private ?SignalRouter $previousRouter = null;

    private ?ClusterContext $previousCluster = null;

    private ?EnvAccessor $previousEnv = null;

    private ?HilosMailer $previousMailer = null;

    /** @var class-string<Hilos> App bound before these isolated master passes */
    private string $previousAppClass;

    /** @var array<string, string|false> Environment values restored after each test */
    private array $previousEnvironment = [];

    private string $logDirectory;

    /** @var list<EventLoop> Native loops released after each fixture */
    private array $loops = [];

    protected function setUp(): void
    {
        $this->previousRuntime = Hilos::$rt;
        $this->previousRouter = Hilos::$sr;
        $this->previousCluster = Hilos::$cluster;
        $this->previousEnv = Hilos::$env;
        $this->previousMailer = Hilos::$mail;
        $this->previousAppClass = Hilos::appClass();
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, Hilos::class);
        foreach ([
            EnvConstants::HILOS_PROTECTED_MODE_QUIESCE_TIMEOUT->name => '31',
            EnvConstants::HILOS_PROTECTED_MODE_SILENCE_TIMEOUT->name => '601',
            EnvConstants::HILOS_PROTECTED_MODE_ALERT_INTERVAL->name => '901',
            EnvConstants::HILOS_PROTECTED_MODE_ALERT_EMAILS->name => 'ops@example.test',
            EnvConstants::MAIL_WORKER_COUNT->name => '1',
        ] as $name => $value) {
            $this->previousEnvironment[$name] = getenv($name);
            putenv($name . '=' . $value);
        }
        Hilos::$env = new EnvAccessor();
        Hilos::$cluster = null;
        Hilos::$rt = new WatchdogPublicationRtContext();
        Hilos::$rt->mountFeatureItem(ProtectedModeRuntime::RT_ITEM, ProtectedModeRuntime::create());
        RtTruthSourceRegistry::registerDaemon(ProtectedModeRuntime::RT_ITEM);
        $this->logDirectory = (string)tempnam(sys_get_temp_dir(), 'watchdog-publication');
        unlink($this->logDirectory);
        mkdir($this->logDirectory);
        file_put_contents($this->logDirectory . '/daemon.log', '');
        Logger::setLogFile($this->logDirectory . '/daemon.log');
    }

    protected function tearDown(): void
    {
        foreach ($this->loops as $loop) {
            $loop->close();
        }
        RtTruthSourceRegistry::unregisterDaemon(ProtectedModeRuntime::RT_ITEM);
        Hilos::$rt = $this->previousRuntime;
        Hilos::$sr = $this->previousRouter;
        Hilos::$cluster = $this->previousCluster;
        Hilos::$env = $this->previousEnv;
        Hilos::$mail = $this->previousMailer;
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->previousAppClass);
        foreach ($this->previousEnvironment as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
        Logger::resetLogFile();
        foreach (glob($this->logDirectory . '/*') as $file) {
            unlink($file);
        }
        rmdir($this->logDirectory);
        parent::tearDown();
    }

    public function testStandalonePublishesOneChangedSnapshotAndAnIdenticalPassQueuesNothing(): void
    {
        $manager = $this->manager();
        $router = $this->router();

        $this->tick($manager);

        $this->assertCount(1, $router->updates);
        $this->assertSame(ProtectedModeRuntime::ID, $router->updates[0]->stateId);
        $this->assertSame([
            ProtectedModeRuntime::watchdogQuiesceTimeoutSeconds => 31,
            ProtectedModeRuntime::watchdogSilenceTimeoutSeconds => 601,
            ProtectedModeRuntime::watchdogAlertIntervalSeconds => 901,
            ProtectedModeRuntime::watchdogAlertEmails => ['ops@example.test'],
        ], $router->updates[0]->row);
        $this->assertSame(ProtectedModeRuntime::PHASE_INACTIVE, Hilos::$rt->hilosProtectedModeRuntime->phase);
        $this->assertNull(Hilos::$rt->hilosProtectedModeRuntime->watchdogManualRestartIsNormal);
        $this->tick($manager);
        $this->assertCount(1, $router->updates);
    }

    /**
     * @param string $key The only value that differs from the watchdog's next snapshot
     * @param mixed $value A different, valid value already published on the runtime row
     */
    #[DataProvider('changedFields')]
    public function testEveryOneOfTheSevenValuesParticipatesInTheComparison(string $key, mixed $value): void
    {
        $manager = $this->manager();
        $router = $this->router();
        $expected = new ProtectedModeWatchdogSnapshot(null, [], 31, 601, 901, ['ops@example.test'], null);
        Hilos::$rt->hilosProtectedModeRuntime->actions->updateWatchdog(new ProtectedModeWatchdogSnapshot(...[
            ...$expected->toArray(),
            $key => $value,
        ]));
        $router->updates = [];

        $this->tick($manager);

        $this->assertCount(1, $router->updates);
        $this->assertSame([$key => $expected->toArray()[$key]], $router->updates[0]->row);
        $this->tick($manager);
        $this->assertCount(1, $router->updates);
    }

    /** @return iterable<string, array{string, mixed}> Independent changes to each publication field */
    public static function changedFields(): iterable
    {
        yield 'verdict' => [ProtectedModeRuntime::watchdogVerdict, 'silent'];
        yield 'pending nodes' => [ProtectedModeRuntime::watchdogPendingNodeIds, ['node-b']];
        yield 'quiesce timeout' => [ProtectedModeRuntime::watchdogQuiesceTimeoutSeconds, 32];
        yield 'silence timeout' => [ProtectedModeRuntime::watchdogSilenceTimeoutSeconds, 602];
        yield 'alert interval' => [ProtectedModeRuntime::watchdogAlertIntervalSeconds, 902];
        yield 'recipients' => [ProtectedModeRuntime::watchdogAlertEmails, []];
        yield 'restart policy' => [ProtectedModeRuntime::watchdogManualRestartIsNormal, false];
    }

    public function testPublicationUsesTheItemActionOwnershipCheck(): void
    {
        $manager = $this->manager();
        $router = $this->router();
        RtTruthSourceRegistry::unregisterDaemon(ProtectedModeRuntime::RT_ITEM);
        $before = Hilos::$rt->hilosProtectedModeRuntime->toArray();

        try {
            $this->tick($manager);
            $this->fail('Publication must go through the guarded item action');
        } catch (RtTruthSourceWriteNotAllowedException) {
            $this->assertSame($before, Hilos::$rt->hilosProtectedModeRuntime->toArray());
            $this->assertSame([], $router->updates);
        }
    }

    public function testFollowerDoesNotJudgeOrMailAndTheLeaderPublishesTheSameStandingFreeze(): void
    {
        $manager = $this->manager();
        $router = $this->router();
        $mailer = new WatchdogPublicationMailer();
        Hilos::$mail = $mailer;
        Hilos::$rt->hilosProtectedModeRuntime->actions->restoreFromDisk(ProtectedModeRuntime::fromRow([
            ...ProtectedModeRuntime::create()->toArray(),
            ProtectedModeRuntime::phase => ProtectedModeRuntime::PHASE_ACTIVE,
            ProtectedModeRuntime::operation => 'restore',
            ProtectedModeRuntime::initiatorAgentType => 'hilos_backup',
            ProtectedModeRuntime::startedAt => time(),
        ]));
        $router->updates = [];
        new ReflectionProperty(DaemonManager::class, 'protectedModeWatchdog')->getValue($manager)->markRestoredFromDisk();
        Hilos::$cluster = new ClusterContext();
        Hilos::$cluster->registerLeadership(new PendingLeadership());

        new ReflectionMethod(DaemonManager::class, 'runGuardedIteration')->invoke($manager, microtime(true));

        $this->assertSame([], $router->updates);
        $this->assertSame([], $mailer->sent);
        $this->assertNull(Hilos::$rt->hilosProtectedModeRuntime->watchdogVerdict);
        $this->assertNull(Hilos::$rt->hilosProtectedModeRuntime->watchdogQuiesceTimeoutSeconds);
        $this->assertStringNotContainsString('Failure in the daemon loop', (string)file_get_contents($this->logDirectory . '/daemon.log'));
        Hilos::$cluster->registerLeadership(new StandaloneLeadership());

        new ReflectionMethod(DaemonManager::class, 'runGuardedIteration')->invoke($manager, microtime(true));

        $this->assertCount(1, $router->updates);
        $this->assertCount(1, $mailer->sent);
        $this->assertSame('restored-from-disk', Hilos::$rt->hilosProtectedModeRuntime->watchdogVerdict);
        $this->assertSame(ProtectedModeRuntime::PHASE_ACTIVE, Hilos::$rt->hilosProtectedModeRuntime->phase);
    }

    public function testNoRuntimeStateProducesNoPublication(): void
    {
        $manager = $this->manager();
        $router = $this->router();
        Hilos::$rt = null;

        $this->tick($manager);

        $this->assertSame([], $router->updates);
    }

    public function testChangedWorkerSettingsCueAnIdlePublisherButDuplicateReportsDoNot(): void
    {
        $manager = $this->manager();
        $router = $this->router();
        $this->tick($manager);
        $this->tick($manager);
        $watchdog = $this->watchdog($manager);
        self::assertFalse($watchdog->hasReadyWork());
        $watchdog->onProtectedModeSettings(true);
        self::assertTrue($watchdog->hasReadyWork());
        $this->tick($manager);
        self::assertTrue(Hilos::$rt->hilosProtectedModeRuntime->watchdogManualRestartIsNormal);
        self::assertCount(2, $router->updates);
        $this->tick($manager);
        $watchdog->onProtectedModeSettings(true);
        self::assertFalse($watchdog->hasReadyWork());
        $watchdog->onProtectedModeSettings(false);
        $this->tick($manager);
        self::assertFalse(Hilos::$rt->hilosProtectedModeRuntime->watchdogManualRestartIsNormal);
        self::assertCount(3, $router->updates);
    }

    public function testSharedFanoutKeepsDiagnosticsAndWatchdogIndependentAcrossDependentDetach(): void
    {
        $manager = $this->manager();
        $server = new ReflectionClass(WatchdogDiagnosticServer::class)->newInstanceWithoutConstructor();
        $binding = new ReflectionProperty(DaemonManager::class, 'protectedModeSourceBinding')->getValue($manager);
        $server->registerProtectedModeSourceBinding($binding);
        $loop = new ReflectionProperty(DaemonManager::class, 'eventLoop')->getValue($manager);
        new ReflectionMethod(WorkerServer::class, 'attachProtectedModeEventLoop')->invoke($server, $loop);
        $this->tick($manager);
        $this->tick($manager);
        $checks = $server->checks;
        Hilos::$rt->hilosProtectedModeRuntime->actions->enterActive();
        self::assertTrue($this->watchdog($manager)->hasReadyWork());
        self::assertSame($checks, $server->checks, 'The row callback cannot publish reentrantly');
        $this->tick($manager);
        self::assertGreaterThan($checks, $server->checks);
        $this->tick($manager);
        self::assertFalse($loop->hasReadyWork());
        new ReflectionMethod(WorkerServer::class, 'detachProtectedModeEventLoop')->invoke($server);
        Hilos::$rt->hilosProtectedModeRuntime->actions->markProgress();
        self::assertTrue($this->watchdog($manager)->hasReadyWork(), 'Diagnostics departure must leave the watchdog source attached');
        $this->tick($manager);
        $this->tick($manager);
        new ReflectionMethod(WorkerServer::class, 'attachProtectedModeEventLoop')->invoke($server, $loop);
        $this->tick($manager);
        $binding->removeWatchdog($this->watchdog($manager));
        $this->watchdog($manager)->detachEventLoop();
        $checks = $server->checks;
        Hilos::$rt->hilosProtectedModeRuntime->actions->enterVerifying();
        $loop->tick();
        self::assertGreaterThan($checks, $server->checks, 'Watchdog departure must leave diagnostics attached');
        new ReflectionMethod(WorkerServer::class, 'detachProtectedModeEventLoop')->invoke($server);
    }

    public function testSourceReplacementRemountNullAndEscapedCallbackUseCurrentWeakBinding(): void
    {
        $manager = $this->manager();
        $this->router();
        $this->tick($manager);
        $this->tick($manager);
        $old = Hilos::$rt;
        $binding = new ReflectionProperty(DaemonManager::class, 'protectedModeSourceBinding')->getValue($manager);
        $stale = new ReflectionProperty(ProtectedModeSourceBinding::class, 'wake')->getValue($binding);
        Hilos::$rt = new WatchdogPublicationRtContext();
        $this->tick($manager);
        self::assertNull(Hilos::$rt->hilosProtectedModeRuntime);
        $stale();
        self::assertFalse($this->watchdog($manager)->hasReadyWork());
        $old->noteProtectedModeChanged();
        self::assertFalse($this->watchdog($manager)->hasReadyWork());
        Hilos::$rt->mountFeatureItem(ProtectedModeRuntime::RT_ITEM, ProtectedModeRuntime::create());
        $this->tick($manager);
        self::assertSame(31, Hilos::$rt->hilosProtectedModeRuntime->watchdogQuiesceTimeoutSeconds);
        Hilos::$rt->mountFeatureItem(ProtectedModeRuntime::RT_ITEM, ProtectedModeRuntime::create());
        self::assertNull(Hilos::$rt->hilosProtectedModeRuntime->watchdogQuiesceTimeoutSeconds);
        $this->tick($manager);
        self::assertSame(31, Hilos::$rt->hilosProtectedModeRuntime->watchdogQuiesceTimeoutSeconds);
        $weak = WeakReference::create($old);
        unset($old);
        self::assertNull($weak->get());
        $this->tick($manager);
        $departingWake = new ReflectionProperty(ProtectedModeSourceBinding::class, 'wake')->getValue($binding);
        $departing = WeakReference::create(Hilos::$rt);
        Hilos::$rt = null;
        self::assertNull($departing->get());
        $departingWake();
        self::assertFalse($this->watchdog($manager)->hasReadyWork(), 'An expired old source is not a binding to null');
        $this->tick($manager);
        $this->tick($manager);
        self::assertFalse($this->watchdog($manager)->hasReadyWork());
    }

    public function testCollectedClusterContextIsReconciledAsANewStandaloneBasis(): void
    {
        Hilos::$cluster = new ClusterContext();
        Hilos::$cluster->registerLeadership(new PendingLeadership());
        $manager = $this->manager();
        $this->tick($manager);
        $this->tick($manager);
        self::assertFalse($this->watchdog($manager)->hasReadyWork());
        $departed = WeakReference::create(Hilos::$cluster);
        Hilos::$cluster = null;
        self::assertNull($departed->get());
        $manager->reconcileProtectedModeSource();
        self::assertTrue($this->watchdog($manager)->hasReadyWork(), 'An expired weak context is not the previously bound null basis');
        $this->tick($manager);
        $this->tick($manager);
        self::assertFalse($this->watchdog($manager)->hasReadyWork());
    }

    public function testPartialDiffAndFullSnapshotWakeTheSameWatchdogAfterMutation(): void
    {
        $manager = $this->manager();
        $this->router();
        $this->tick($manager);
        $this->tick($manager);
        RtSyncApplicator::applyCreated(new RtSyncCreatedSignalData(ProtectedModeRuntime::RT_ITEM, ProtectedModeRuntime::ID, [
            ProtectedModeRuntime::phase => ProtectedModeRuntime::PHASE_ACTIVE,
            ProtectedModeRuntime::startedAt => 'invalid',
        ]), false);
        self::assertTrue($this->watchdog($manager)->hasReadyWork());
        self::assertSame(ProtectedModeRuntime::PHASE_ACTIVE, Hilos::$rt->hilosProtectedModeRuntime->phase);
        $this->tick($manager);
        $this->tick($manager);
        self::assertFalse($this->watchdog($manager)->hasReadyWork());
        RtSnapshot::replace(ProtectedModeRuntime::RT_ITEM, [ProtectedModeRuntime::ID => [
            ProtectedModeRuntime::phase => ProtectedModeRuntime::PHASE_VERIFYING,
        ]]);
        self::assertTrue($this->watchdog($manager)->hasReadyWork());
        $this->tick($manager);
        $this->tick($manager);
        self::assertFalse($this->watchdog($manager)->hasReadyWork());
        RtSnapshot::replace(ProtectedModeRuntime::RT_ITEM, ['unrelated' => []]);
        self::assertFalse($this->watchdog($manager)->hasReadyWork());
    }

    public function testExactSourceRemovalPreservesReplacementAndTheBindingDoesNotRetainItsManager(): void
    {
        $manager = $this->manager();
        $this->tick($manager);
        $this->tick($manager);
        $binding = new ReflectionProperty(DaemonManager::class, 'protectedModeSourceBinding')->getValue($manager);
        $stale = new ReflectionProperty(ProtectedModeSourceBinding::class, 'wake')->getValue($binding);
        $calls = 0;
        Hilos::$rt->setProtectedModeWake(static function () use (&$calls): void { ++$calls; });
        $binding->removeWatchdog($this->watchdog($manager));
        Hilos::$rt->noteProtectedModeChanged();
        self::assertSame(1, $calls);
        $stale();
        self::assertFalse($this->watchdog($manager)->hasReadyWork());
        $weak = WeakReference::create($manager);
        unset($manager);
        gc_collect_cycles();
        self::assertNull($weak->get());
    }

    /**
     * @param DaemonManager $manager Master whose native watchdog is observed
     * @return ProtectedModeWatchdog Current instance, retaining its live fingerprint across bindings
     */
    private function watchdog(DaemonManager $manager): ProtectedModeWatchdog
    {
        return new ReflectionProperty(DaemonManager::class, 'protectedModeWatchdog')->getValue($manager);
    }

    /** @return WatchdogPublicationManager Master bound to the real native lifetime */
    private function manager(): WatchdogPublicationManager
    {
        $manager = new WatchdogPublicationManager();
        $loop = new EventLoop();
        $this->loops[] = $loop;
        new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($manager, $loop);
        new ReflectionMethod(DaemonManager::class, 'attachProtectedModeWatchdog')->invoke($manager);

        return $manager;
    }

    /** @return WatchdogPublicationRouter Router recording the real update sync seam */
    private function router(): WatchdogPublicationRouter
    {
        Hilos::$sr = new WatchdogPublicationRouter();

        return Hilos::$sr;
    }

    /** @param DaemonManager $manager Master whose bounded publication pass is driven */
    private function tick(DaemonManager $manager): void
    {
        $manager->reconcileProtectedModeSource();
        new ReflectionProperty(DaemonManager::class, 'eventLoop')->getValue($manager)->tick();
        new ReflectionProperty(DaemonManager::class, 'protectedModeWatchdog')->getValue($manager)->dispatchReady();
    }
}

/** Mounted node-local row without a project registry. */
final class WatchdogPublicationRtContext extends RtContext
{
    /** No project collections are needed by this master bridge. */
    public function configure(): void
    {
    }
}

/** Master with no sockets or agent processes. */
final class WatchdogPublicationManager extends DaemonManager
{
    /** Drives the fixture native base inside the ordinary master guard. */
    protected function processEventLoop(): void
    {
        parent::processEventLoop();
    }

    /** @return SignalRouter Empty project routing */
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    /** @return AgentManagerDaemon Agent manager whose factory must remain unused */
    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new WatchdogPublicationAgentManager();
    }
}

/** Records each actual update frame, even when a full iteration drains the queue. */
final class WatchdogPublicationRouter extends SignalRouter
{
    /** @var list<RtSyncUpdatedSignalData> Update sync calls for the protected-mode singleton */
    public array $updates = [];

    /**
     * @param string $signalName Queued sync name
     * @param RtSyncSignalDataInterface $signalData Queued sync payload
     */
    public function queueRtSyncSignal(string $signalName, RtSyncSignalDataInterface $signalData): void
    {
        if ($signalData instanceof RtSyncUpdatedSignalData && $signalData->collectionKey === ProtectedModeRuntime::RT_ITEM) {
            $this->updates[] = $signalData;
        }
        parent::queueRtSyncSignal($signalName, $signalData);
    }
}

/** Agent manager that refuses an accidental attempt to start an agent. */
final class WatchdogPublicationAgentManager extends AgentManagerDaemon
{
    /**
     * @param string $agentType Unused agent type
     * @param ?string $agentIndex Unused agent index
     * @return AgentDaemonInterface Never returned
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new AgentDaemonCreationFailedException('No agent starts in the watchdog publication test');
    }
}

/** Captures raw-send requests without a delivery process. */
final class WatchdogPublicationMailer extends HilosMailer
{
    /** @var list<EmailMessage|MailSendSignalData> Mail requests */
    public array $sent = [];

    /** @param EmailMessage|MailSendSignalData $message Requested raw mail */
    public function send(EmailMessage|MailSendSignalData $message): void
    {
        $this->sent[] = $message;
    }
}

/** Records the independent diagnostic duty without a worker process or unrelated roster. */
final class WatchdogDiagnosticServer extends WorkerServer
{
    public int $checks = 0;

    /** No socket or worker starts in this isolated source fixture. */
    public function onStart(): void
    {
    }

    /** Counts only actual guarded diagnostic portions. */
    protected function publishProtectedModeAgentDiagnostics(): void
    {
        ++$this->checks;
    }
}
