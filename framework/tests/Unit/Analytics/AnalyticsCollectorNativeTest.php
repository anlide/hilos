<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Analytics;

use Hilos\Constants\HttpConstants;
use Hilos\Core\Analytics\AnalyticsCollector;
use Hilos\Core\Analytics\AnalyticsJournalOutbox;
use Hilos\Core\Analytics\AnalyticsJournalRecord;
use Hilos\Core\Analytics\AnalyticsLossReason;
use Hilos\Core\Analytics\DTO\AnalyticsJournalAppendSignalData;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\EventLoop\EventLoopTimer;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncSignalDataInterface;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Hilos;
use Hilos\Runtime\RtSnapshot;
use Hilos\Runtime\RtSyncApplicator;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Runtime\View\Collection\RtCollection;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Item\RtItem;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Socket;
use WeakReference;

/** Native scheduling over the real outbox basis, without a blanket daemon iteration or collector tick as a wake source. */
final class AnalyticsCollectorNativeTest extends TestCase
{
    private EventLoop $loop;
    private DaemonManager $manager;
    private ?AnalyticsCollector $previousCollector;
    private ?RtContext $previousRuntime;
    private ?SignalRouter $previousRouter;

    /** @var list<Socket> Already-readable I/O used instead of waiting for future timers */
    private array $sockets = [];

    /** Empty captures are idle; description-only pending uses the original outbox interval through more records and I/O. */
    public function testEmptyAndFutureDeadlineThroughUnrelatedIo(): void
    {
        $this->mount(ProtectedModeRuntime::PHASE_INACTIVE);
        $collector = Hilos::$ac = new AnalyticsCollector();
        $this->invoke('attachAnalyticsEventLoop');
        $collector->startApiRequest(null, HttpConstants::METHOD_GET, '/health', null, null, null);
        $this->assertNull($this->timer()->getDeadline());
        $reads = Hilos::$rt->reads;
        $this->invoke('dispatchAnalyticsFlush');
        $this->loop->tick();
        $this->assertSame($reads, Hilos::$rt->reads);
        $this->assertFalse($this->loop->hasReadyWork());
        $this->basis($collector, $this->nowMs() + 60_000);
        $collector->openWorkerSession(1, false);
        $deadline = $this->timer()->getDeadline();
        $this->assertSame($this->outbox($collector)->nextFlushAt() / 1000, $deadline);
        $collector->logWorkerSystemSignal('later', null);
        $collector->openAgentSession('ordinary', null);
        $this->assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $this->sockets));
        $this->loop->registerRead($this->sockets[0], function (): void {
            socket_read($this->sockets[0], 1);
        });
        socket_write($this->sockets[1], 'x');
        $this->loop->runOnce();
        $this->invoke('dispatchAnalyticsFlush');
        $this->assertSame($deadline, $this->timer()->getDeadline());
        $this->assertSame([], $this->frames());
    }

    /** Loss-only and description-only data really append after native expiry, keeping epoch losses distinct from the interval. */
    public function testActualDueAndEpochLosses(): void
    {
        $collector = Hilos::$ac = new AnalyticsCollector();
        $this->basis($collector, $this->nowMs() - 2000);
        $box = $this->outbox($collector);
        $box->countLoss(AnalyticsLossReason::RESTORE, 2, 1_800_000_000_000, 1_800_000_000_123);
        $this->invoke('attachAnalyticsEventLoop');
        $this->native();
        $this->assertSame([], $this->frames(), 'native work only cues the guard');
        $this->invoke('dispatchAnalyticsFlush');
        $frames = $this->frames();
        $this->assertCount(1, $frames);
        $this->assertSame([], $frames[0]->lines);
        $this->assertSame(['reason' => 'restore', 'events' => 2, 'fromTs' => 1_800_000_000_000, 'toTs' => 1_800_000_000_123],
            $frames[0]->losses[0]->toArray());
        $this->assertNull($this->timer()->getDeadline());
        $this->basis($collector, $this->nowMs() - 2000);
        $collector->openWorkerSession(1, false);
        $this->native();
        $this->invoke('dispatchAnalyticsFlush');
        $frames = $this->frames();
        $this->assertCount(1, $frames);
        $this->assertSame(0, $frames[0]->events);
        $this->assertSame(AnalyticsJournalRecord::TYPE_WORKER_SESSION, json_decode($frames[0]->lines[0], true)['t']);
        $this->assertFalse($this->loop->hasReadyWork());
    }

    /** Full/forced/empty flush reset one interval and cancel an already activated old batch callback. */
    public function testActivatedForcedAndFullFlushCannotFlushTheNewBatchEarly(): void
    {
        $collector = Hilos::$ac = new AnalyticsCollector();
        $collector->openWsConnection('old', null);
        $this->basis($collector, $this->nowMs() - 2000);
        $this->invoke('attachAnalyticsEventLoop');
        $activated = false;
        $this->loop->createReadyWork(function () use ($collector, &$activated): void {
            $activated = $this->timer()->getDeadline() === null;
            $collector->flush();
            $collector->openWsConnection('new', null);
        })->markReady();
        $this->loop->runOnce();
        $this->invoke('dispatchAnalyticsFlush');
        $this->assertTrue($activated);
        $this->assertCount(1, $this->frames());
        $deadline = $this->timer()->getDeadline();
        $this->assertGreaterThan(hrtime(true) / 1e9, $deadline);
        $this->loop->tick();
        $this->invoke('dispatchAnalyticsFlush');
        $this->assertSame([], $this->frames());
        $box = $this->outbox($collector);
        $box->add(AnalyticsJournalRecord::wsConnectionOpen('large', str_repeat('x', AnalyticsJournalOutbox::FLUSH_BYTES), 123),
            [], 1_800_000_000_123, $this->nowMs());
        $this->assertCount(1, $this->frames());
        $this->assertNull($this->timer()->getDeadline());
        $collector->flush();
        $collector->openWsConnection('after-empty', null);
        $this->assertGreaterThanOrEqual($deadline, $this->timer()->getDeadline());
        $this->assertFalse($this->loop->hasReadyWork());
    }

    /** Stops retain their actual stop moment through hold/lift, which transfers them before a still-future flush. */
    public function testHeldStopAndLossLiftBeforeTheOriginalInterval(): void
    {
        $this->mount(ProtectedModeRuntime::PHASE_INACTIVE);
        $collector = Hilos::$ac = new AnalyticsCollector();
        $collector->openWorkerSession(1, false);
        $collector->openAgentSession('ordinary', null);
        $collector->flush();
        $this->frames();
        $this->basis($collector, $this->nowMs() + 60_000);
        $collector->logWorkerSystemSignal('discarded', null);
        $this->invoke('attachAnalyticsEventLoop');
        $originalDeadline = $this->timer()->getDeadline();
        $this->mount(ProtectedModeRuntime::PHASE_ACTIVE);
        $this->native();
        $this->invoke('dispatchAnalyticsFlush');
        $collector->closeAgentSession('ordinary', null);
        $held = new ReflectionProperty(AnalyticsCollector::class, 'heldStops')->getValue($collector);
        $this->assertCount(1, $held);
        $stopAt = $held[0]->record['ts'];
        $reads = Hilos::$rt->reads;
        $this->loop->tick();
        $this->invoke('dispatchAnalyticsFlush');
        $this->assertSame($reads, Hilos::$rt->reads);
        $this->assertNull($this->timer()->getDeadline());
        $this->assertSame([], $this->frames());
        $this->mount(ProtectedModeRuntime::PHASE_VERIFYING);
        $this->native();
        $this->invoke('dispatchAnalyticsFlush');
        $this->assertSame([], new ReflectionProperty(AnalyticsCollector::class, 'heldStops')->getValue($collector));
        $this->assertSame($originalDeadline, $this->timer()->getDeadline());
        $this->assertSame([], $this->frames());
        $this->basis($collector, $this->nowMs() - 2000);
        $collector->invalidateProtectedMode();
        $this->native();
        $this->invoke('dispatchAnalyticsFlush');
        $frames = $this->frames();
        $this->assertCount(1, $frames);
        $this->assertSame(1, $frames[0]->events);
        $this->assertSame(1, $frames[0]->losses[0]->events);
        $this->assertGreaterThan(1_700_000_000_000, $frames[0]->losses[0]->fromTs);
        $this->assertSame($stopAt, json_decode($frames[0]->lines[2], true)['ts']);
        $this->assertFalse($this->loop->hasReadyWork());
    }

    /**
     * Every existing protected-source invalidation reaches native readiness before the interval, including partial refusal.
     *
     * @param string $kind Existing local/remote/mount delivery path
     */
    #[DataProvider('gateChanges')]
    public function testProtectedFactsWakeTheGuardWithoutABlanketTick(string $kind): void
    {
        $this->mount(ProtectedModeRuntime::PHASE_INACTIVE);
        $collector = Hilos::$ac = new AnalyticsCollector();
        $collector->openWsConnection('pending', null);
        $this->basis($collector, $this->nowMs() + 60_000);
        $this->invoke('attachAnalyticsEventLoop');
        $this->changeGate($kind, ProtectedModeRuntime::PHASE_ACTIVE);
        $this->invoke('reconcilePeriodicDutySources');
        $this->native();
        $this->invoke('dispatchAnalyticsFlush');
        $this->assertNull($this->timer()->getDeadline());
        $this->assertSame([], $this->frames());
        $reads = Hilos::$rt->reads;
        $this->loop->tick();
        $this->invoke('dispatchAnalyticsFlush');
        $this->assertSame($reads, Hilos::$rt->reads);
        $this->assertFalse($this->loop->hasReadyWork());
        $this->basis($collector, $this->nowMs() - 2000);
        $this->changeGate($kind, ProtectedModeRuntime::PHASE_VERIFYING);
        $this->invoke('reconcilePeriodicDutySources');
        $this->native();
        $this->invoke('dispatchAnalyticsFlush');
        $frames = $this->frames();
        $this->assertCount(1, $frames);
        $this->assertSame(1, $frames[0]->losses[0]->events);
        $this->assertSame(AnalyticsLossReason::RESTORE, $frames[0]->losses[0]->reason);
        $this->assertFalse($this->loop->hasReadyWork());
    }

    /** @return iterable<string, array{string}> All existing protected-mode source paths */
    public static function gateChanges(): iterable
    {
        foreach (['local', 'local-refused', 'created', 'updated', 'created-refused', 'updated-refused',
            'full', 'scoped', 'full-refused', 'scoped-refused', 'remount', 'context'] as $kind) {
            yield $kind => [$kind];
        }
    }

    /** An activated old binding cannot touch a new facade or survive detach; rebind retains the original batch basis. */
    public function testActivatedRebindReplacementAndDetach(): void
    {
        foreach (['rebind', 'replacement', 'detach'] as $change) {
            $collector = Hilos::$ac = new AnalyticsCollector();
            $collector->openWsConnection('aged', null);
            $this->basis($collector, $this->nowMs() - 2000);
            $original = $this->outbox($collector)->nextFlushAt();
            $this->invoke('attachAnalyticsEventLoop');
            $activated = false;
            $this->loop->createReadyWork(function () use ($change, &$activated): void {
                $activated = $this->timer()->getDeadline() === null;
                if ($change === 'rebind') {
                    $this->invoke('attachAnalyticsEventLoop');
                } elseif ($change === 'replacement') {
                    Hilos::$ac = new AnalyticsCollector();
                    $this->basis(Hilos::$ac, $this->nowMs() + 60_000);
                    Hilos::$ac->openWsConnection('future', null);
                    $this->invoke('reconcilePeriodicDutySources');
                } else {
                    $this->invoke('detachAnalyticsEventLoop');
                }
            })->markReady();
            $this->loop->runOnce();
            $this->assertTrue($activated);
            $this->invoke('dispatchAnalyticsFlush');
            $this->assertSame([], $this->frames(), 'old activation must not run guarded business');
            if ($change === 'rebind') {
                $this->assertSame($original / 1000, $this->timer()->getDeadline());
                $this->native();
                $this->invoke('dispatchAnalyticsFlush');
                $this->assertCount(1, $this->frames());
            }
            $this->invoke('detachAnalyticsEventLoop');
        }
    }

    /** Wrong or empty snapshots leave a future deadline and the cached gate untouched. */
    public function testIrrelevantSnapshotsDoNotWakeTheCollector(): void
    {
        $this->mount(ProtectedModeRuntime::PHASE_INACTIVE);
        $collector = Hilos::$ac = new AnalyticsCollector();
        $collector->openWsConnection('pending', null);
        $this->basis($collector, $this->nowMs() + 60_000);
        $this->invoke('attachAnalyticsEventLoop');
        $deadline = $this->timer()->getDeadline();
        $reads = Hilos::$rt->reads;
        RtSnapshot::replace(ProtectedModeRuntime::RT_ITEM, []);
        RtSnapshot::replaceScope(ProtectedModeRuntime::RT_ITEM, [ProtectedModeRuntime::ID], ['other-id' => []]);
        RtSyncApplicator::applyUpdated(new RtSyncUpdatedSignalData(
            ProtectedModeRuntime::RT_ITEM, 'other-id', [ProtectedModeRuntime::phase => ProtectedModeRuntime::PHASE_ACTIVE],
        ), false);
        $this->native();
        $this->invoke('dispatchAnalyticsFlush');
        $this->assertSame($deadline, $this->timer()->getDeadline());
        $this->assertSame($reads, Hilos::$rt->reads);
        $this->assertFalse($this->loop->hasReadyWork());
    }

    /** A released weak target is still a binding to remove when the facade itself becomes null. */
    public function testCollectorRemovalCancelsItsDeadlineEvenAfterTheWeakTargetHasGone(): void
    {
        Hilos::$ac = new AnalyticsCollector();
        Hilos::$ac->openWsConnection('future', null);
        $this->basis(Hilos::$ac, $this->nowMs() + 60_000);
        $reference = WeakReference::create(Hilos::$ac);
        $this->invoke('attachAnalyticsEventLoop');
        $this->assertNotNull($this->timer()->getDeadline());
        Hilos::$ac = null;
        $this->assertNull($reference->get());
        $this->invoke('reconcilePeriodicDutySources');
        $this->assertNull($this->timer()->getDeadline());
        $this->native();
        $this->assertFalse($this->loop->hasReadyWork());
    }

    /** Replaced collectors are released, exact old observers are harmless, and null/context swap lifts held losses. */
    public function testFacadeReplacementExactObserverAndContextNull(): void
    {
        $this->mount(ProtectedModeRuntime::PHASE_ACTIVE);
        $old = Hilos::$ac = new AnalyticsCollector();
        $old->openWsConnection('held', null);
        $this->invoke('attachAnalyticsEventLoop');
        $oldWake = new ReflectionProperty(DaemonManager::class, 'analyticsWake')->getValue($this->manager);
        $oldReference = WeakReference::create($old);
        $collector = Hilos::$ac = new AnalyticsCollector();
        $collector->openWsConnection('new-held', null);
        $this->invoke('reconcilePeriodicDutySources');
        $old->removeScheduleWake($oldWake);
        $oldWake();
        unset($old);
        $this->assertNull($oldReference->get());
        $this->assertNull($this->timer()->getDeadline());
        Hilos::$rt = null;
        $this->basis($collector, $this->nowMs() - 2000);
        $this->invoke('reconcilePeriodicDutySources');
        $this->native();
        $this->invoke('dispatchAnalyticsFlush');
        $this->assertCount(1, $this->frames());
        $collector->openWsConnection('before-swap', null);
        $collector->forgetReplacedDatabase();
        $this->basis($collector, $this->nowMs() - 2000);
        $this->invoke('attachAnalyticsEventLoop');
        $this->native();
        $this->invoke('dispatchAnalyticsFlush');
        $frames = $this->frames();
        $this->assertCount(1, $frames);
        $this->assertSame(1, $frames[0]->losses[0]->events);
        $this->invoke('detachAnalyticsEventLoop');
        $oldWake();
        $collector->openWsConnection('after-detach', null);
        $this->loop->tick();
        $this->assertFalse($this->loop->hasReadyWork());
    }

    /** Real framework mounts supply the singleton; no shared source slot is replaced. */
    protected function setUp(): void
    {
        $this->previousCollector = Hilos::$ac;
        $this->previousRuntime = Hilos::$rt;
        $this->previousRouter = Hilos::$sr;
        Hilos::$ac = null;
        Hilos::$rt = null;
        Hilos::$sr = new SignalRouter();
        $this->loop = new EventLoop();
        $this->manager = $this->getMockBuilder(DaemonManager::class)->disableOriginalConstructor()->getMock();
        new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($this->manager, $this->loop);
    }

    /** Detaches the exact source and timer before the native base. */
    protected function tearDown(): void
    {
        $this->invoke('detachAnalyticsEventLoop');
        $this->loop->cleanup();
        foreach ($this->sockets as $socket) {
            socket_close($socket);
        }
        RtTruthSourceRegistry::unregisterDaemon(ProtectedModeRuntime::RT_ITEM);
        Hilos::$ac = $this->previousCollector;
        Hilos::$rt = $this->previousRuntime;
        Hilos::$sr = $this->previousRouter;
        parent::tearDown();
    }

    /** Runs only native callbacks, never a collector or master maintenance sweep. */
    private function native(): void
    {
        for ($portion = 0; $portion < 3; ++$portion) {
            $this->loop->tick();
        }
    }

    /** @param string $method Causal binding or guarded production duty */
    private function invoke(string $method): void
    {
        new ReflectionMethod(DaemonManager::class, $method)->invoke($this->manager);
    }

    /** @return EventLoopTimer Actual bound master timer */
    private function timer(): EventLoopTimer
    {
        return new ReflectionProperty(DaemonManager::class, 'analyticsFlushTimer')->getValue($this->manager);
    }

    /**
     * @param AnalyticsCollector $collector Exact current source
     * @return AnalyticsJournalOutbox Its one interval and batch owner
     */
    private function outbox(AnalyticsCollector $collector): AnalyticsJournalOutbox
    {
        return new ReflectionProperty(AnalyticsCollector::class, 'outbox')->getValue($collector);
    }

    /**
     * @param AnalyticsCollector $collector Source whose business basis is aged before native arming
     * @param int $milliseconds Concrete monotonic last-flush basis
     */
    private function basis(AnalyticsCollector $collector, int $milliseconds): void
    {
        new ReflectionProperty(AnalyticsJournalOutbox::class, 'lastFlushAtMs')->setValue($this->outbox($collector), $milliseconds);
    }

    /** @return int Monotonic process milliseconds, never a record timestamp */
    private function nowMs(): int
    {
        return (int)(hrtime(true) / 1e6);
    }

    /** @return list<AnalyticsJournalAppendSignalData> Actual queued journal append frames */
    private function frames(): array
    {
        $frames = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof AgentSignalData && $signal->data->data instanceof AnalyticsJournalAppendSignalData) {
                $frames[] = $signal->data->data;
            }
        }
        return $frames;
    }

    /** @param string $phase Framework phase to mount into the current context */
    private function mount(string $phase): void
    {
        Hilos::$rt ??= new AnalyticsNativeRtContext();
        Hilos::$rt->mountFeatureItem(ProtectedModeRuntime::RT_ITEM, ProtectedModeRuntime::fromRow([
            ProtectedModeRuntime::phase => $phase,
            ProtectedModeRuntime::passHashes => [],
            ProtectedModeRuntime::admittedSessionTokenHashes => [],
            ProtectedModeRuntime::circleSessionTokenHashes => [],
            ProtectedModeRuntime::circleNamedCount => 0,
            ProtectedModeRuntime::enteredFromBrowser => false,
        ]));
    }

    /**
     * @param string $kind Existing delivery path
     * @param string $phase New phase
     */
    private function changeGate(string $kind, string $phase): void
    {
        if ($kind === 'context') {
            Hilos::$rt = new AnalyticsNativeRtContext();
            $this->mount($phase);
            return;
        }
        if ($kind === 'remount') {
            $this->mount($phase);
            return;
        }
        if (str_starts_with($kind, 'local')) {
            RtTruthSourceRegistry::registerDaemon(ProtectedModeRuntime::RT_ITEM);
            if ($kind === 'local-refused') {
                Hilos::$sr = new AnalyticsNativeRefusingRouter();
            }
            try {
                if ($phase === ProtectedModeRuntime::PHASE_ACTIVE) {
                    Hilos::$rt->hilosProtectedModeRuntime->actions->enterActive();
                } else {
                    Hilos::$rt->hilosProtectedModeRuntime->actions->enterVerifying();
                }
                $this->assertSame('local', $kind);
            } catch (InvalidArgumentException) {
                $this->assertSame('local-refused', $kind);
            }
            Hilos::$sr = new SignalRouter();
            return;
        }
        $row = [ProtectedModeRuntime::phase => $phase];
        if (str_ends_with($kind, '-refused')) {
            $row[ProtectedModeRuntime::startedAt] = 'invalid';
        }
        if (str_starts_with($kind, 'created')) {
            RtSyncApplicator::applyCreated(new RtSyncCreatedSignalData(ProtectedModeRuntime::RT_ITEM, ProtectedModeRuntime::ID, $row), false);
        } elseif (str_starts_with($kind, 'updated')) {
            RtSyncApplicator::applyUpdated(new RtSyncUpdatedSignalData(ProtectedModeRuntime::RT_ITEM, ProtectedModeRuntime::ID, $row), false);
        } elseif (str_starts_with($kind, 'scoped')) {
            RtSnapshot::replaceScope(ProtectedModeRuntime::RT_ITEM, [ProtectedModeRuntime::ID], [ProtectedModeRuntime::ID => $row]);
        } else {
            RtSnapshot::replace(ProtectedModeRuntime::RT_ITEM, [ProtectedModeRuntime::ID => $row]);
        }
    }
}

/** Counts gate reads without replacing any runtime writer. */
final class AnalyticsNativeRtContext extends RtContext
{
    public int $reads = 0;

    /** No project state is needed by this fixture. */
    public function configure(): void
    {
    }

    /**
     * @param string $name Current alias
     * @return RtCollection|RtItem|null Actual framework view
     */
    public function __get(string $name): RtCollection|RtItem|null
    {
        if ($name === ProtectedModeRuntime::RT_ITEM) {
            ++$this->reads;
        }
        return parent::__get($name);
    }
}

/** Refuses only after the real local phase mutation and its scheduling notification. */
final class AnalyticsNativeRefusingRouter extends SignalRouter
{
    /**
     * @param string $signalName Existing RT sync name
     * @param RtSyncSignalDataInterface $signalData Existing RT sync payload
     */
    public function queueRtSyncSignal(string $signalName, RtSyncSignalDataInterface $signalData): void
    {
        throw new InvalidArgumentException('Refused test sync');
    }
}
