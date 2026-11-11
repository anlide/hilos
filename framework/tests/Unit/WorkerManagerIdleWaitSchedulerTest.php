<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentIdleTracker;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Analytics\AnalyticsCollector;
use Hilos\Core\Analytics\AnalyticsJournalOutbox;
use Hilos\Core\Analytics\AnalyticsJournalRecord;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\Context\HeldFacetCounts;
use Hilos\Core\Daemon\ContainedFailure;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Core\Daemon\TickDrivenIo;
use Hilos\Core\Daemon\TickDrivenIoRegistry;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\SignalRouter;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogStub;
use Hilos\Hilos;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Socket\Worker\WorkerDaemonClient;
use Hilos\Utils\RepeatedFailureWindows;
use Hilos\Utils\WorkerTickFailureLog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Socket;

/** Real scheduler decisions on controlled epoch time, with physical waiting covered by the service/socket suites. */
final class WorkerManagerIdleWaitSchedulerTest extends TestCase
{
    private string $previousAppClass;
    private ?EnvAccessor $previousEnv;
    private string|false $previousZone;

    /** @var list<IdleSchedulerManager> Socket owners released after each case */
    private array $managers = [];

    protected function setUp(): void
    {
        $this->previousAppClass = Hilos::appClass();
        $this->previousEnv = Hilos::$env;
        $this->previousZone = getenv(EnvConstants::HILOS_PROJECT_TIME_ZONE->name);
        putenv(EnvConstants::HILOS_PROJECT_TIME_ZONE->name . '=UTC');
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, IdleSchedulerHilos::class);
        Hilos::$ac = null;
        Hilos::$browser = null;
        Hilos::$rt = null;
        WorkerTickFailureLog::reset();
        IdleSchedulerRule::$searches = 0;
        IdleSchedulerRule::$failSearch = false;
    }

    protected function tearDown(): void
    {
        foreach ($this->managers as $manager) {
            $manager->close();
        }
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->previousAppClass);
        Hilos::$env = $this->previousEnv;
        putenv(EnvConstants::HILOS_PROJECT_TIME_ZONE->name . ($this->previousZone === false ? '' : '=' . $this->previousZone));
        Hilos::$sr = null;
        Hilos::$ac = null;
        Hilos::$browser = null;
        Hilos::$rt = null;
        ExecutionContext::clear();
        WorkerTickFailureLog::reset();
        parent::tearDown();
    }

    /** @return IdleSchedulerManager Worker with a real socket and a recording final-wait seam */
    private function manager(): IdleSchedulerManager
    {
        $manager = new IdleSchedulerManager();
        $this->managers[] = $manager;
        return $manager;
    }

    public function testCurrentRosterMinimumAndSpentWorkInBothWorkerKinds(): void
    {
        $manager = $this->manager();
        foreach ([false, true] as $monopolistic) {
            $manager->setMonopolistic($monopolistic);
            self::assertSame(1000.1, $manager->finishPass(1000.0));
            $long = new IdleSchedulerAgent('long');
            $manager->add($long);
            self::assertSame(1600.0, $manager->finishPass(1000.0));
            $manager->now = 1125.0;
            self::assertSame(1600.0, $manager->finishPass(1000.0));
            $medium = new class('medium') extends IdleSchedulerAgent {
                protected const MAX_IDLE_SLEEP_SECONDS = 2;
            };
            $manager->add($medium);
            self::assertNull($manager->finishPass(1000.0), 'Spent work must exhaust the shorter allowance.');
            $manager->now = 1000.0;
            self::assertSame(1002.0, $manager->finishPass(1000.0));
            $manager->remove($medium->getId());
            self::assertSame(1600.0, $manager->finishPass(1000.0));
            $default = new class('default') extends IdleSchedulerAgent {
                protected const MAX_IDLE_SLEEP_SECONDS = 0.1;
            };
            $manager->add($default);
            self::assertSame(1000.1, $manager->finishPass(1000.0));
            $manager->remove($default->getId());
            $direct = $this->createMock(AgentInterface::class);
            $direct->method('getId')->willReturn('direct');
            $direct->method('getType')->willReturn('direct');
            $manager->add($direct);
            self::assertSame(1000.1, $manager->finishPass(1000.0));
            $manager->remove('direct');
            self::assertSame(1600.0, $manager->finishPass(1000.0));
            $manager->remove($long->getId());
            self::assertSame(1000.1, $manager->finishPass(1000.0));
        }
        self::assertSame([], $manager->workerState('pageSignalRouters'), 'A read must never create a page router.');
    }

    public function testInheritedProjectWorkerHookRetainsItsOwnCadence(): void
    {
        $manager = new class extends IdleSchedulerHookManager {};
        $this->managers[] = $manager;
        $manager->add(new IdleSchedulerAgent());
        self::assertSame(1000.1, $manager->finishPass(1000.0));
        self::assertSame(0, $manager->ticks, 'Hook recognition must not execute the hook.');
    }

    /** @return iterable<string, array{string}> Earlier caps that make owner predicate reads unnecessary */
    public static function shortCaps(): iterable
    {
        foreach (['empty roster', 'default agent', 'failed agent', 'cron', 'spent work', 'worker hook', 'worker queue'] as $cap) {
            yield $cap => [$cap];
        }
    }

    /** @param string $cap Source that already bounds the pass to 100 ms or sooner */
    #[DataProvider('shortCaps')]
    public function testEarlierCapsSkipMaintenanceOwners(string $cap): void
    {
        if ($cap === 'worker hook') {
            $manager = new IdleSchedulerHookManager();
            $this->managers[] = $manager;
        } else {
            $manager = $this->manager();
        }
        $agent = $cap === 'default agent' ? new class extends IdleSchedulerAgent {
            protected const MAX_IDLE_SLEEP_SECONDS = 0.1;
        } : ($cap === 'cron' ? new IdleSchedulerCronAgent() : new IdleSchedulerAgent());
        if ($cap !== 'empty roster') {
            $manager->add($agent);
        }
        if ($cap === 'failed agent') {
            $manager->setWorkerState('failedAgentTicks', [$agent->getId() => true]);
        } elseif ($cap === 'cron') {
            new ReflectionProperty(AbstractAgent::class, 'cronDeadline')->setValue($agent, 1000.05);
        } elseif ($cap === 'worker queue') {
            $manager->setWorkerState('pendingUploadReceiptIds', ['pending']);
        }
        if ($cap === 'spent work') {
            $manager->now = 1600.01;
        }
        $router = $this->getMockBuilder(PageSignalRouter::class)->disableOriginalConstructor()->getMock();
        $router->expects($this->never())->method('hasDeferredWork');
        $manager->setWorkerState('pageSignalRouters', [$agent->getId() => $router]);
        Hilos::$browser = $this->getMockBuilder(BrowserContext::class)->disableOriginalConstructor()->getMock();
        Hilos::$browser->expects($this->never())->method('hasHeldFacetCounts');
        Hilos::$sr = $this->createMock(SignalRouter::class);
        Hilos::$sr->expects($this->never())->method('hasQueuedSignals');

        $expected = match ($cap) {
            'cron' => 1000.05,
            'spent work' => null,
            default => 1000.1,
        };
        self::assertSame($expected, $manager->finishPass(1000.0));
    }

    public function testShortRosterMemberStillAllowsLaterDueCronAndValidatesEveryAllowance(): void
    {
        $manager = $this->manager();
        $manager->add(new class('default') extends IdleSchedulerAgent {
            protected const MAX_IDLE_SLEEP_SECONDS = 0.1;
        });
        $cron = new IdleSchedulerCronAgent('cron');
        new ReflectionProperty(AbstractAgent::class, 'cronDeadline')->setValue($cron, 999.99);
        $manager->add($cron);
        self::assertNull($manager->finishPass(1000.0));
        self::assertSame([], $manager->pauses);
        $manager->add(new class('invalid') extends IdleSchedulerAgent {
            protected const MAX_IDLE_SLEEP_SECONDS = 0;
        });

        $this->expectException(AgentException::class);
        $manager->finishPass(1000.0);
    }

    /** @return iterable<string, array{string}> Worker-owned queues, independently */
    public static function workerQueues(): iterable
    {
        foreach (['parkedFrames', 'pendingUploadReceiptIds', 'cronFireWaits', 'cronFiredAcks'] as $field) {
            yield $field => [$field];
        }
    }

    /** @param string $field Existing owner queue to populate */
    #[DataProvider('workerQueues')]
    public function testWorkerQueueGuardsReleaseAsSoonAsEmpty(string $field): void
    {
        $manager = $this->manager();
        $manager->add(new IdleSchedulerAgent());
        $manager->setWorkerState($field, ['pending']);
        self::assertSame(1000.1, $manager->finishPass(1000.0));
        self::assertSame(1000.1, $manager->finishPass(1000.0));
        self::assertSame(['pending'], $manager->workerState($field));
        $manager->setWorkerState($field, []);
        self::assertSame(1600.0, $manager->finishPass(1000.0));
    }

    /** @return iterable<string, array{string}> Router-owned work pools */
    public static function routerQueues(): iterable
    {
        foreach (['deferredActions', 'bulkRuns', 'pendingFrames'] as $field) {
            yield $field => [$field];
        }
    }

    /** @param string $field Existing router pool to populate */
    #[DataProvider('routerQueues')]
    public function testRouterGuardReadsEachPoolWithoutDrainingIt(string $field): void
    {
        $manager = $this->manager();
        $agent = new IdleSchedulerAgent();
        $manager->add($agent);
        $router = new ReflectionClass(PageSignalRouter::class)->newInstanceWithoutConstructor();
        $manager->setWorkerState('pageSignalRouters', [$agent->getId() => $router]);
        self::assertFalse($router->hasDeferredWork());
        self::assertSame(1600.0, $manager->finishPass(1000.0));
        $queue = new ReflectionProperty(PageSignalRouter::class, $field);
        $queue->setValue($router, ['pending']);
        self::assertTrue($router->hasDeferredWork());
        self::assertSame(1000.1, $manager->finishPass(1000.0));
        self::assertSame(['pending'], $queue->getValue($router));
        $queue->setValue($router, []);
        self::assertFalse($router->hasDeferredWork());
        self::assertSame(1600.0, $manager->finishPass(1000.0));
    }

    public function testHeldFacetCountsRemainWithTheirBrowserOwner(): void
    {
        $manager = $this->manager();
        $agent = new IdleSchedulerAgent();
        $manager->add($agent);
        Hilos::$browser = new class extends BrowserContext {};
        $counts = [new HeldFacetCounts($agent->getId(), 'page', 'accept', 'table', null, 1000.0)];
        $field = new ReflectionProperty(BrowserContext::class, 'heldFacetCounts');
        $field->setValue(Hilos::$browser, $counts);
        self::assertSame(1000.1, $manager->finishPass(1000.0));
        self::assertSame($counts, $field->getValue(Hilos::$browser));
        $field->setValue(Hilos::$browser, []);
        self::assertSame(1600.0, $manager->finishPass(1000.0));
    }

    /** @return iterable<string, array{string}> Every analytics buffer, including description-only and loss-only batches */
    public static function analyticsQueues(): iterable
    {
        foreach (['descriptions', 'records', 'losses', 'heldStops'] as $field) {
            yield $field => [$field];
        }
    }

    /** @param string $field Analytics buffer that requires service */
    #[DataProvider('analyticsQueues')]
    public function testAnalyticsGuardChecksDataRatherThanFlushTime(string $field): void
    {
        $manager = $this->manager();
        $manager->add(new IdleSchedulerAgent());
        Hilos::$ac = new AnalyticsCollector();
        $outbox = new ReflectionProperty(AnalyticsCollector::class, 'outbox')->getValue(Hilos::$ac);
        self::assertInstanceOf(AnalyticsJournalOutbox::class, $outbox);
        self::assertTrue($outbox->isFlushDue(PHP_INT_MAX));
        self::assertFalse(Hilos::$ac->hasPendingData());
        self::assertSame(1600.0, $manager->finishPass(1000.0));
        $owner = $field === 'heldStops' ? Hilos::$ac : $outbox;
        $queue = new ReflectionProperty($owner, $field);
        $queue->setValue($owner, ['pending']);
        self::assertTrue(Hilos::$ac->hasPendingData());
        self::assertSame(1000.1, $manager->finishPass(1000.0));
        self::assertSame(['pending'], $queue->getValue($owner));
        $queue->setValue($owner, []);
        self::assertFalse(Hilos::$ac->hasPendingData());
        self::assertSame(1600.0, $manager->finishPass(1000.0));
    }

    public function testFailureWindowsAreObservedWithoutCreatingOrClosingThem(): void
    {
        $manager = $this->manager();
        $manager->add(new IdleSchedulerAgent());
        $field = new ReflectionProperty(WorkerTickFailureLog::class, 'windows');
        $previous = $field->getValue();
        try {
            $field->setValue(null, null);
            self::assertFalse(WorkerTickFailureLog::hasPendingWindows());
            self::assertSame(1600.0, $manager->finishPass(1000.0));
            self::assertNull($field->getValue());
            $windows = new RepeatedFailureWindows(1, 60.0);
            $field->setValue(null, $windows);
            $windows->admits('failure', 1000.0);
            self::assertSame(1000.1, $manager->finishPass(1000.0));
            self::assertTrue($windows->hasPendingWindows());
            self::assertCount(1, $windows->closeExpired(1060.0));
            self::assertSame(1600.0, $manager->finishPass(1000.0));
            $windows->admits('replaced', 1000.0);
            $windows->admits('replaced', 1060.0);
            self::assertTrue($windows->hasPendingWindows());
            self::assertCount(2, $windows->closeExpired(1120.0));
            self::assertFalse($windows->hasPendingWindows());
            self::assertSame(1600.0, $manager->finishPass(1000.0));
        } finally {
            $field->setValue(null, $previous);
        }
    }

    public function testAnalyticsFlushAfterDispatchKeepsTheQueuedBatchOnAShortPass(): void
    {
        $manager = $this->manager();
        $manager->add(new IdleSchedulerAgent());
        Hilos::$ac = new AnalyticsCollector();
        $outbox = new ReflectionProperty(AnalyticsCollector::class, 'outbox')->getValue(Hilos::$ac);
        self::assertInstanceOf(AnalyticsJournalOutbox::class, $outbox);
        new ReflectionProperty(AnalyticsJournalOutbox::class, 'lastFlushAtMs')->setValue($outbox, 0);
        $outbox->add(AnalyticsJournalRecord::workerSystemSignal('worker', 'event', null, 0), [], 0, 0);
        self::assertFalse(Hilos::$sr->hasQueuedSignals());
        Hilos::$ac->tick();
        self::assertFalse(Hilos::$ac->hasPendingData());
        self::assertTrue(Hilos::$sr->hasQueuedSignals());
        self::assertSame(1000.1, $manager->finishPass(1000.0));
        self::assertSame(1000.1, $manager->finishPass(1000.0));
        self::assertNotNull(Hilos::$sr->getNextQueuedSignal(), 'Reading the guard must not consume the batch.');
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
        self::assertFalse(Hilos::$sr->hasQueuedSignals());
        self::assertSame(1600.0, $manager->finishPass(1000.0));
    }

    public function testMessagesWritesAndTickDrivenIoKeepTheTenMillisecondBranch(): void
    {
        $manager = $this->manager();
        $manager->add(new IdleSchedulerAgent());
        self::assertNull($manager->finishPass(1000.0, true));
        self::assertSame([10000], $manager->pauses);
        $manager->client->pendingWrite = true;
        self::assertNull($manager->finishPass(1000.0));
        $manager->client->pendingWrite = false;
        $io = new class implements TickDrivenIo {
            public bool $busy = true;
            /** @return bool Controlled operation state */
            public function isInFlight(): bool
            {
                return $this->busy;
            }
        };
        TickDrivenIoRegistry::track($io);
        self::assertNull($manager->finishPass(1000.0));
        self::assertSame([10000, 10000, 10000], $manager->pauses);
        $io->busy = false;
        self::assertSame(1600.0, $manager->finishPass(1000.0));
    }

    public function testCronMinuteCrossingRunsTheMissedMinuteBeforeAnyWaitAndThenAdvances(): void
    {
        $manager = $this->manager();
        $agent = new IdleSchedulerCronAgent();
        $rule = new IdleSchedulerRule('minute', '* * * * *');
        $agent->rules = [$rule];
        $manager->add($agent);
        $minute = (float)strtotime('2026-10-08 10:15:00 UTC');
        $manager->now = $minute - 0.01;
        $rule->now = (int)$minute - 1;
        $rule->lastRun = floor(($minute - 1) / 60);
        $agent->afterTick = function () use ($manager, $minute): void {
            $manager->now = $minute + 0.01;
        };
        $manager->tickAgents();
        self::assertSame($minute, $agent->getCronDeadline());
        self::assertNull($manager->finishPass($minute - 0.01));
        $manager->setWorkerState('pendingUploadReceiptIds', ['pending']);
        self::assertNull($manager->finishPass($minute - 0.01, true), 'Short guards must not mask a due cron.');
        self::assertSame([], $manager->pauses);
        self::assertSame(0, $agent->runs);
        $manager->setWorkerState('pendingUploadReceiptIds', []);
        $agent->afterTick = null;
        $rule->now = (int)$minute;
        $manager->tickAgents();
        self::assertSame(1, $agent->runs);
        self::assertSame((int)$minute, $rule->lastFiredAt);
        self::assertSame($minute + 60, $agent->getCronDeadline());
        self::assertSame($minute + 60, $manager->finishPass($manager->now));
        self::assertSame(2, IdleSchedulerRule::$searches);
        self::assertSame(2, $agent->observations);
        self::assertFalse($rule->shouldRun());
    }

    public function testRuleAddChangeRemovalAndReportRepairReachTheFinalMinimum(): void
    {
        $manager = $this->manager();
        $agent = new IdleSchedulerCronAgent();
        $manager->add($agent);
        $manager->now = (float)strtotime('2026-10-08 10:14:00 UTC');
        $manager->tickAgents();
        self::assertSame($manager->now + 600, $manager->finishPass($manager->now));
        $agent->rules = [new IdleSchedulerRule('later', '0 12 * * *')];
        $manager->tickAgents();
        self::assertSame($manager->now + 600, $manager->finishPass($manager->now));
        $agent->rules[0]->expression = '15 10 * * *';
        $manager->tickAgents();
        self::assertSame($manager->now + 60, $manager->finishPass($manager->now));
        $agent->rules = [];
        $manager->tickAgents();
        self::assertSame($manager->now + 600, $manager->finishPass($manager->now));
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, IdleSchedulerDaemonHilos::class);
        $agent->rules = [new IdleSchedulerRule('daily', '0 12 * * *')];
        $manager->tickAgents();
        self::assertSame($manager->now + 60, $manager->finishPass($manager->now));
        $manager->now += 1;
        $agent->rules[0]->expression = '0 13 * * *';
        $manager->tickAgents();
        self::assertSame($manager->now + 60, $manager->finishPass($manager->now));
        $manager->now += 60;
        self::assertNull($manager->finishPass($manager->now), 'An overdue nonempty report must be repaired now.');
        $manager->tickAgents();
        self::assertSame($manager->now + 60, $manager->finishPass($manager->now));
        $agent->rules = [];
        $manager->tickAgents();
        self::assertNull($agent->getCronReportDeadline());
        self::assertSame($manager->now + 600, $manager->finishPass($manager->now));
    }

    /** @return iterable<string, array{string}> Failures before and during deadline preparation */
    public static function unitFailures(): iterable
    {
        yield 'tick' => ['tick'];
        yield 'rules' => ['rules'];
        yield 'calendar' => ['calendar'];
    }

    /** @param string $stage Hook that must fail inside the real agent unit */
    #[DataProvider('unitFailures')]
    public function testFailedUnitRetriesBrieflyWithoutStaleDeadlineSpinAndHealthyCronStillRuns(string $stage): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, IdleSchedulerDaemonHilos::class);
        $manager = $this->manager();
        $agent = new IdleSchedulerCronAgent('failing');
        $agent->rules = [new IdleSchedulerRule('daily', '0 12 * * *')];
        $manager->add($agent);
        $manager->now = (float)strtotime('2026-10-08 10:14:00 UTC');
        $manager->tickAgents();
        $agent->failureStage = $stage;
        $agent->rules[0]->expression = '* * * * *';
        IdleSchedulerRule::$failSearch = $stage === 'calendar';
        $manager->tickAgents();
        self::assertCount(1, $manager->failures);
        self::assertSame($manager->now + 0.1, $manager->finishPass($manager->now));
        $manager->now += 120;
        self::assertSame($manager->now + 0.1, $manager->finishPass($manager->now), 'A stale expired deadline must not spin.');
        $healthy = new IdleSchedulerCronAgent('healthy');
        $rule = new IdleSchedulerRule('healthy-minute', '*/1 * * * *');
        $rule->now = (int)$manager->now;
        $healthy->rules = [$rule];
        $manager->add($healthy);
        $manager->tickAgents();
        self::assertSame(1, $healthy->runs);
        self::assertSame(2, count($manager->failures));
        IdleSchedulerRule::$failSearch = false;
        $agent->failureStage = null;
        $agent->rules = [];
        $manager->tickAgents();
        WorkerTickFailureLog::reset();
        self::assertSame($manager->now + 60, $manager->finishPass($manager->now));
        $manager->remove($healthy->getId());
        self::assertSame($manager->now + 600, $manager->finishPass($manager->now));
    }

    public function testUnsearchableCalendarKeepsShortWaitsWithoutResearchingInTheScheduler(): void
    {
        $manager = $this->manager();
        $agent = new IdleSchedulerCronAgent();
        $agent->rules = [new IdleSchedulerRule('never', '0 0 31 2 *')];
        $manager->add($agent);
        self::assertNull($manager->finishPass(1000.0), 'The first cron observation must happen before a long wait.');
        $manager->tickAgents();
        self::assertSame(1000.1, $manager->finishPass(1000.0));
        self::assertSame(1000.1, $manager->finishPass(1000.0));
        self::assertSame(1, $agent->observations);
        self::assertSame(1, IdleSchedulerRule::$searches);
        $manager->now = 1000.1;
        $manager->tickAgents();
        self::assertSame(1000.2, $manager->finishPass(1000.1));
        self::assertSame(1, IdleSchedulerRule::$searches);
    }

    public function testIdleWindowCrossedAfterItsUnitRequestsAnotherPassBeforeSleeping(): void
    {
        $manager = $this->manager();
        $agent = new IdleSchedulerAgent();
        $manager->add($agent);
        $manager->tracker()->noteStarted($agent->getId(), 1000.0);
        $manager->now = 1239.99;
        $manager->tickAgents();
        self::assertTrue($manager->has($agent->getId()));
        $manager->now = 1240.01;
        self::assertNull($manager->finishPass(1239.99));
        $manager->tickAgents();
        self::assertFalse($manager->has($agent->getId()));
        self::assertSame(1240.11, $manager->finishPass(1240.01));
    }

    public function testFutureIdleBoundaryRenewalSubscriberResetAndExpiredBlockedWindowNeverSpin(): void
    {
        $manager = $this->manager();
        $agent = new IdleSchedulerAgent();
        $manager->add($agent);
        $tracker = $manager->tracker();
        $tracker->noteStarted($agent->getId(), 1000.0);
        self::assertSame(1240.0, $manager->finishPass(1000.0));
        $tracker->noteAddressed($agent->getId(), 1100.0);
        self::assertSame(1340.0, $manager->finishPass(1000.0));
        $tracker->noteSubscriber($agent->getId(), 'accept', 1120.0);
        self::assertSame(1600.0, $manager->finishPass(1000.0));
        $tracker->dropSubscriber($agent->getId(), 'accept', 1150.0);
        self::assertSame(1390.0, $manager->finishPass(1000.0));
        $manager->now = 1390.0;
        $agent->busy = true;
        $manager->tickAgents();
        self::assertTrue($manager->has($agent->getId()));
        self::assertSame(1990.0, $manager->finishPass(1390.0));
        $agent->busy = false;
        Hilos::$rt = new class extends RtContext {
            /** This test needs only the framework singleton aliases. */
            public function configure(): void
            {
            }
        };
        Hilos::$rt->mountFeatureItem(ProtectedModeRuntime::RT_ITEM, ProtectedModeRuntime::fromRow([
            ProtectedModeRuntime::phase => ProtectedModeRuntime::PHASE_ACTIVE,
            ProtectedModeRuntime::passHashes => [],
            ProtectedModeRuntime::admittedSessionTokenHashes => [],
            ProtectedModeRuntime::circleSessionTokenHashes => [],
            ProtectedModeRuntime::circleNamedCount => 0,
            ProtectedModeRuntime::enteredFromBrowser => false,
        ]));
        $manager->tickAgents();
        self::assertTrue($manager->has($agent->getId()));
        self::assertSame(1990.0, $manager->finishPass(1390.0));
        Hilos::$rt = null;
        $manager->tickAgents();
        self::assertFalse($manager->has($agent->getId()), 'Equality must stop through the ordinary idle path.');
        self::assertNull($tracker->getIdleDeadline($agent->getId(), 240));
        self::assertSame(1390.1, $manager->finishPass(1390.0));
    }
}

abstract class IdleSchedulerHilos extends Hilos
{
    public const array AGENTS = [
        IdleSchedulerAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => IdleSchedulerAgent::class,
            AgentRegistryKey::IDLE_TIMEOUT => 240,
        ],
    ];
    protected const array FEATURES = [];
}

abstract class IdleSchedulerDaemonHilos extends IdleSchedulerHilos
{
    protected const array FEATURES = [HilosFeature::DAEMON];
}

class IdleSchedulerAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'unit_idle_scheduler';
    protected const MAX_IDLE_SLEEP_SECONDS = 600;
    public bool $busy = false;

    /** @param string|null $index Identity used to distinguish agents on one worker */
    public function __construct(?string $index = null)
    {
        $this->agentIndex = $index;
    }

    /** @return bool Whether the idle stop must be deferred */
    public function hasWorkInFlight(): bool
    {
        return $this->busy;
    }

    /** No resources were opened by this probe. */
    public function onStop(): void
    {
    }
}

final class IdleSchedulerCronAgent extends IdleSchedulerAgent
{
    /** @var list<CronRule> Mutable live schedule */
    public array $rules = [];
    public int $runs = 0;
    public int $observations = 0;
    public ?Closure $afterTick = null;
    public ?string $failureStage = null;

    /** Runs ordinary shouldRun checks before any simulated completion cost. */
    public function onTick(): void
    {
        if ($this->failureStage === 'tick') {
            throw new RuntimeException('tick failed');
        }
        foreach ($this->rules as $rule) {
            if ($rule->shouldRun()) {
                $this->runs++;
            }
        }
        if ($this->afterTick !== null) {
            ($this->afterTick)();
        }
    }

    /** @return list<CronRule> One post-tick schedule observation */
    protected function cronRules(): array
    {
        $this->observations++;
        if ($this->failureStage === 'rules') {
            throw new RuntimeException('rules failed');
        }
        return $this->rules;
    }
}

final class IdleSchedulerRule extends CronRule
{
    public static int $searches = 0;
    public static bool $failSearch = false;
    public int $now = 0;

    /**
     * @param string $expression Live expression
     * @param int $after Strict search boundary
     * @return int|null Calendar result
     */
    public static function nextRunAfter(string $expression, int $after): ?int
    {
        self::$searches++;
        if (self::$failSearch && $expression === '* * * * *') {
            throw new RuntimeException('calendar failed');
        }
        return parent::nextRunAfter($expression, $after);
    }

    /** @return int Rule's controlled check time */
    protected function currentTimestamp(): int
    {
        return $this->now;
    }
}

class IdleSchedulerManager extends WorkerManager
{
    public float $now = 1000.0;
    public IdleSchedulerClient $client;
    private Socket $peer;
    /** @var list<float> Final epoch deadlines */
    public array $waits = [];
    /** @var list<int> Active pass durations */
    public array $pauses = [];
    /** @var list<string> Contained unit diagnostics */
    public array $failures = [];

    public function __construct()
    {
        parent::__construct(1);
        $pair = [];
        TestCase::assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        $this->peer = $pair[1];
        $this->client = new IdleSchedulerClient($pair[0]);
        $this->daemonClient = $this->client;
    }

    /** Releases both real socket handles. */
    public function close(): void
    {
        $this->client->close();
        socket_close($this->peer);
    }

    /** @param bool $monopolistic Worker kind */
    public function setMonopolistic(bool $monopolistic): void
    {
        $this->isMonopolistic = $monopolistic;
    }

    /** @param AgentInterface $agent Current roster member */
    public function add(AgentInterface $agent): void
    {
        $this->agentManager->addAgent($agent->getId(), $agent);
    }

    /** @param string $id Roster member to remove */
    public function remove(string $id): void
    {
        $this->agentManager->removeAgent($id);
    }

    /**
     * @param string $id Roster member to inspect
     * @return bool Whether the member is still live
     */
    public function has(string $id): bool
    {
        return $this->agentManager->hasAgent($id);
    }

    /** @return AgentIdleTracker Existing worker-local lifetime owner */
    public function tracker(): AgentIdleTracker
    {
        return new ReflectionProperty(WorkerManager::class, 'agentIdleTracker')->getValue($this);
    }

    /** Runs the actual contained agent units, then drains their frames as the ordinary dispatch would. */
    public function tickAgents(): void
    {
        Closure::bind(function (): void {
            $this->tickAgents();
        }, $this, WorkerManager::class)();
        while (Hilos::$sr?->getNextQueuedSignal() !== null) {
        }
    }

    /**
     * @param float $start Work-pass start
     * @param bool $handled Active-message guard
     * @return float|null Final epoch deadline, or null when no socket wait was requested
     */
    public function finishPass(float $start, bool $handled = false): ?float
    {
        $this->waits = [];
        Closure::bind(function () use ($start, $handled): void {
            $this->waitForWork($start, $handled);
        }, $this, WorkerManager::class)();
        return $this->waits[0] ?? null;
    }

    /**
     * @param string $field Worker-owned state field
     * @param array $value Prepared queue contents
     */
    public function setWorkerState(string $field, array $value): void
    {
        new ReflectionProperty(WorkerManager::class, $field)->setValue($this, $value);
    }

    /**
     * @param string $field Worker-owned state field
     * @return array Current queue contents
     */
    public function workerState(string $field): array
    {
        return new ReflectionProperty(WorkerManager::class, $field)->getValue($this);
    }

    /** @return float Controlled epoch seconds */
    protected function waitTime(): float
    {
        return $this->now;
    }

    /** @param float $deadline Final epoch budget passed to the existing parent-service wait */
    protected function awaitDaemonWork(float $deadline): void
    {
        $this->waits[] = $deadline;
    }

    /**
     * @param float $loopStartTime Work-pass start
     * @param int $targetLoopTimeMicroseconds Requested active pass duration
     */
    protected function sleepWithPreciseTiming(float $loopStartTime, int $targetLoopTimeMicroseconds = 10000): void
    {
        $this->pauses[] = $targetLoopTimeMicroseconds;
    }

    /** @param ContainedFailure $failure Contained unit failure */
    protected function onTickFailure(ContainedFailure $failure): void
    {
        $this->failures[] = $failure->failure->getMessage();
    }

    /** @return SignalRouter Ordinary in-process signal queue */
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    /** @return AgentManager Mutable worker roster */
    protected function createAgentManager(): AgentManager
    {
        return new class extends AgentManager {
            /**
             * @param string $agentType Unused type
             * @param string|null $agentIndex Unused index
             * @return AgentInterface No factory calls are expected
             */
            protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
            {
                throw new RuntimeException('The test installs existing agents.');
            }
        };
    }
}

class IdleSchedulerHookManager extends IdleSchedulerManager
{
    public int $ticks = 0;

    /** Records project hook execution. */
    protected function onTick(): void
    {
        $this->ticks++;
    }
}

final class IdleSchedulerClient extends WorkerDaemonClient
{
    public bool $pendingWrite = false;

    /** @param Socket $socket Real descriptor for the scheduler's socket-presence guard */
    public function __construct(Socket $socket)
    {
        $this->socket = $socket;
    }

    /** @return bool The recording transport remains connected */
    public function isConnected(): bool
    {
        return true;
    }

    /** @return bool Controlled write buffer */
    public function hasPendingWrite(): bool
    {
        return $this->pendingWrite;
    }
}
