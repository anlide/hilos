<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\ProtectedMode;

use Closure;
use Hilos\Cluster\ClusterContext;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\EventLoop\EventLoopTimer;
use Hilos\Core\EventLoop\Exception\EventLoopException;
use Hilos\Hilos;
use Hilos\ProtectedMode\DTO\ProtectedModeCircleSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeDisableSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeEnableSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModePassSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeProgressSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeRefreezeSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeVerifySignalData;
use Hilos\ProtectedMode\ProtectedModeAgentFreezer;
use Hilos\ProtectedMode\ProtectedModeEntryGate;
use Hilos\ProtectedMode\ProtectedModeSwitch;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Socket;
use WeakReference;

/**
 * Unit tests for the hold a freeze request waits out while the lift before it is still landing.
 *
 * What is pinned here is the order of two facts, because that order is the whole defect: a freeze
 * entered while a start this node asked for has not been reported takes the roster down mid-start,
 * and the agent whose report arrives next is killed as an orphan and never asked for again. So the
 * gate lets a request through on a settled roster, holds it on an unsettled one, releases it the
 * tick the roster settles, and - because a start that never lands must not make the node
 * unfreezable - goes through anyway once the hold has waited long enough.
 */
final class ProtectedModeEntryGateTest extends TestCase
{
    private FakeFreezeSwitch $switch;

    private FakeRosterFreezer $freezer;

    private ProtectedModeEntryGate $gate;

    private EventLoop $loop;

    /** Bind the existing freeze door to the real master scheduling primitive. */
    protected function setUp(): void
    {
        $this->switch = new FakeFreezeSwitch();
        $this->freezer = new FakeRosterFreezer();
        $this->gate = new ProtectedModeEntryGate();

        Hilos::$cluster = new ClusterContext();
        Hilos::$cluster->registerProtectedMode($this->switch);
        Hilos::$cluster->registerProtectedModeAgentFreezer($this->freezer);
        Hilos::$cluster->registerProtectedModeEntryGate($this->gate);
        $this->loop = new EventLoop();
        $this->gate->attachEventLoop($this->loop);
    }

    /** Release native owners before removing the facade registrations. */
    protected function tearDown(): void
    {
        $this->gate->detachEventLoop();
        $this->loop->cleanup();
        Hilos::$cluster = null;

        parent::tearDown();
    }

    public function testASettledRosterIsFrozenAtOnce(): void
    {
        $this->gate->requestEnter($this->enableData('restore'));

        $this->assertSame(['restore'], $this->switch->entered);
    }

    public function testAFreezeAskedForMidStartIsHeld(): void
    {
        $this->freezer->stillStarting = ['chat'];

        $this->gate->requestEnter($this->enableData('restore'));

        $this->assertSame([], $this->switch->entered);
    }

    /** A direct verification window needs no start-roster read or timer. */
    public function testDirectWindowWithNoEarlierHeldRequestEntersImmediately(): void
    {
        $this->freezer->stillStarting = ['chat'];
        $this->gate->requestEnter(new ProtectedModeEnableSignalData(
            'maintenance', null, null, 'index', null, null,
            ProtectedModeRuntime::ENTRY_MODE_VERIFICATION_WINDOW,
        ));
        $this->assertSame(['maintenance'], $this->switch->entered);
        $this->assertSame(0, $this->freezer->reads);
        $this->assertNull($this->timer()->getDeadline());
        $this->assertFalse($this->loop->hasReadyWork());
    }

    public function testDirectWindowSkipsRosterWaitButFollowsEarlierHeldEnable(): void
    {
        $this->freezer->stillStarting = ['chat'];
        $this->gate->requestEnter($this->enableData('restore'));
        $this->gate->requestEnter(new ProtectedModeEnableSignalData(
            'maintenance', null, null, 'index', null, null,
            ProtectedModeRuntime::ENTRY_MODE_VERIFICATION_WINDOW,
        ));

        $this->assertSame(['restore', 'maintenance'], $this->switch->entered);
    }

    public function testTheHeldFreezeEntersOnTheFactTheRosterSettles(): void
    {
        $this->freezer->stillStarting = ['chat'];
        $this->gate->requestEnter($this->enableData('restore'));

        $this->loop->tick();
        $this->assertSame([], $this->switch->entered);

        $this->freezer->stillStarting = [];
        $this->gate->noteRosterChanged();
        $this->loop->tick();

        $this->assertSame(['restore'], $this->switch->entered);
        $this->loop->tick();
        $this->assertSame(['restore'], $this->switch->entered);
    }

    /** The original five seconds are brought to now; libevent wakes without any socket input. */
    public function testOriginalFiveSecondDeadlineEntersAnUnsettledRosterWithoutInput(): void
    {
        $this->freezer->stillStarting = ['chat'];
        $admittedAt = hrtime(true) / 1e9 - 5.0;
        $this->gate->requestEnter($this->enableData('restore'), $admittedAt);
        $this->assertSame($admittedAt + 5.0, $this->timer()->getDeadline());
        $this->assertFalse($this->loop->hasReadyWork());
        $this->loop->runOnce();
        $this->loop->tick();
        $this->assertSame(['restore'], $this->switch->entered);
        $this->assertNull($this->timer()->getDeadline());
        $this->assertFalse($this->loop->hasReadyWork());
    }

    /** Equality is due, and both observations use the admission's monotonic clock. */
    public function testExactOriginalDeadlineIsDue(): void
    {
        $this->freezer->stillStarting = ['chat'];
        $admittedAt = hrtime(true) / 1e9;
        $this->gate->requestEnter($this->enableData('restore'), $admittedAt);
        $this->gate->noteRosterChanged();
        $this->gate->dispatchReady($admittedAt + 4.0);
        $this->assertSame([], $this->switch->entered);
        $this->assertSame($admittedAt + 5.0, $this->timer()->getDeadline());
        $this->gate->noteRosterChanged();
        $this->gate->dispatchReady($admittedAt + 5.0);
        $this->assertSame(['restore'], $this->switch->entered);
    }

    /** Admission while detached still owns the original due when first attached. */
    public function testInitialAttachWithAnOverduePendingRequestKeepsItsOriginalDeadline(): void
    {
        $this->gate->detachEventLoop();
        $this->freezer->stillStarting = ['chat'];
        $admittedAt = hrtime(true) / 1e9 - 5.0;
        $this->gate->requestEnter($this->enableData('restore'), $admittedAt);
        $this->assertFalse($this->loop->hasReadyWork());
        $this->gate->attachEventLoop($this->loop);
        $this->assertSame($admittedAt + 5.0, $this->timer()->getDeadline());
        $this->loop->tick();
        $this->loop->tick();
        $this->assertSame(['restore'], $this->switch->entered);
        $this->assertFalse($this->loop->hasReadyWork());
    }

    /** Facts coalesce and unrelated I/O neither scans the roster nor renews the deadline. */
    public function testFutureDeadlineSurvivesFactsIoAndRebindWithoutIdlePolling(): void
    {
        $this->freezer->stillStarting = ['chat'];
        $this->gate->requestEnter($this->enableData('restore'));
        $oldTimer = $this->timer();
        $deadline = $oldTimer->getDeadline();
        $generation = new ReflectionProperty(EventLoopTimer::class, 'generation')->getValue($oldTimer);
        $this->assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        try {
            $this->loop->registerRead($pair[0], static function () use ($pair): void {
                socket_read($pair[0], 1);
            });
            for ($index = 0; $index < 3; ++$index) {
                socket_write($pair[1], 'x');
                $this->loop->runOnce();
                $this->assertSame(1, $this->freezer->reads);
            }
            for ($index = 0; $index < 20; ++$index) {
                $this->gate->noteRosterChanged();
            }
            $this->loop->tick();
            $this->assertSame(2, $this->freezer->reads);
            $this->assertSame($generation, new ReflectionProperty(EventLoopTimer::class, 'generation')->getValue($oldTimer));
            $this->gate->attachEventLoop($this->loop);
            $this->loop->tick();
            $this->assertSame($deadline, $this->timer()->getDeadline());
            $this->assertNull($oldTimer->getDeadline());
            $this->assertSame(3, $this->freezer->reads);
            for ($index = 0; $index < 20; ++$index) {
                $this->loop->tick();
            }
            $this->assertSame(3, $this->freezer->reads);
            $this->assertFalse($this->loop->hasReadyWork());
        } finally {
            $this->loop->unregisterRead($pair[0]);
            socket_close($pair[0]);
            socket_close($pair[1]);
        }
    }

    /** A superseding pair consumes the old native activation before either external callback. */
    public function testActivatedOldTimerCannotConsumeAReentrantNewRequest(): void
    {
        $this->freezer->stillStarting = ['chat'];
        $this->gate->requestEnter($this->enableData('restore'), hrtime(true) / 1e9 - 5.0);
        $this->switch->afterEnter = function (ProtectedModeEnableSignalData $data): void {
            if ($data->operation === 'restore') {
                $this->assertNull($this->timer()->getDeadline());
                $this->gate->requestEnter($this->enableData('reentrant'));
            }
        };
        $activated = false;
        $this->loop->createReadyWork(function () use (&$activated): void {
            $activated = $this->timer()->getDeadline() === null;
            $this->gate->requestEnter($this->enableData('import'));
        })->markReady();
        $this->loop->runOnce();
        $this->loop->tick();
        $this->assertTrue($activated);
        $this->assertSame(['restore', 'import'], $this->switch->entered);
        $this->assertNotNull($this->timer()->getDeadline());
        $this->assertFalse($this->loop->hasReadyWork());
        $this->freezer->stillStarting = [];
        $this->gate->noteRosterChanged();
        $this->loop->tick();
        $this->assertSame(['restore', 'import', 'reentrant'], $this->switch->entered);
    }

    /** A timer-driven entry also consumes its slot before an external switch reenters. */
    public function testDueEntryDoesNotCancelTheRequestItsSwitchCreates(): void
    {
        $this->freezer->stillStarting = ['chat'];
        $this->switch->afterEnter = function (ProtectedModeEnableSignalData $data): void {
            if ($data->operation === 'restore') {
                $this->gate->requestEnter($this->enableData('next'));
            }
        };
        $this->gate->requestEnter($this->enableData('restore'), hrtime(true) / 1e9 - 5.0);
        $this->loop->runOnce();
        $this->loop->tick();
        $this->assertSame(['restore'], $this->switch->entered);
        $this->assertNotNull($this->timer()->getDeadline());
        $this->freezer->stillStarting = [];
        $this->gate->noteRosterChanged();
        $this->loop->tick();
        $this->assertSame(['restore', 'next'], $this->switch->entered);
    }

    /** Detached facts and old handles cannot remove or enter the replacement native binding. */
    public function testDetachPreservesPendingAndExactOldCloseCannotCancelReplacement(): void
    {
        $this->freezer->stillStarting = ['chat'];
        $this->gate->requestEnter($this->enableData('restore'));
        $oldTimer = $this->timer();
        $deadline = $oldTimer->getDeadline();
        $oldWork = new ReflectionProperty(ProtectedModeEntryGate::class, 'work')->getValue($this->gate);
        $escapedWork = new ReflectionProperty($oldWork, 'callback')->getValue($oldWork);
        $escapedTimer = new ReflectionProperty($oldTimer, 'callback')->getValue($oldTimer);
        $this->gate->detachEventLoop();
        $this->freezer->stillStarting = [];
        $this->gate->noteRosterChanged();
        $this->assertFalse($this->loop->hasReadyWork());
        $this->gate->attachEventLoop($this->loop);
        $oldTimer->close();
        $oldWork->close();
        $escapedWork();
        $escapedTimer();
        $this->assertSame($deadline, $this->timer()->getDeadline());
        $this->assertSame([], $this->switch->entered);
        $this->loop->tick();
        $this->assertSame(['restore'], $this->switch->entered);
        $this->assertFalse($this->loop->hasReadyWork());
    }

    /** Even live native handles cannot retain an otherwise unreachable gate. */
    public function testNativeBindingKeepsOnlyAWeakOwner(): void
    {
        $gate = new ProtectedModeEntryGate();
        $gate->attachEventLoop($this->loop);
        $this->freezer->stillStarting = ['chat'];
        $gate->requestEnter($this->enableData('restore'), hrtime(true) / 1e9 - 5.0);
        $reference = WeakReference::create($gate);
        unset($gate);
        $this->assertNull($reference->get());
        $this->loop->runOnce();
        $this->loop->tick();
        $this->assertSame([], $this->switch->entered);
        $this->assertFalse($this->loop->hasReadyWork());
    }

    /** A real native arm failure propagates without discarding the held request. */
    public function testArmingFailureIsExposedAndRebindingKeepsTheOriginalRequest(): void
    {
        $this->freezer->stillStarting = ['chat'];
        $this->timer()->close();
        try {
            $this->gate->requestEnter($this->enableData('restore'));
            $this->fail('A closed native timer must refuse arming');
        } catch (EventLoopException) {
            $this->assertSame([], $this->switch->entered);
        }
        $deadline = new ReflectionProperty(ProtectedModeEntryGate::class, 'pendingDeadline')->getValue($this->gate);
        $this->gate->attachEventLoop($this->loop);
        $this->assertSame($deadline, $this->timer()->getDeadline());
        $this->freezer->stillStarting = [];
        $this->loop->tick();
        $this->assertSame(['restore'], $this->switch->entered);
    }

    public function testASecondRequestLetsTheHeldOneInFirst(): void
    {
        $this->freezer->stillStarting = ['chat'];
        $this->gate->requestEnter($this->enableData('restore'));

        $this->gate->requestEnter($this->enableData('import'));

        $this->assertSame(['restore', 'import'], $this->switch->entered);
    }

    public function testANodeWithNoFreezerFreezesAtOnce(): void
    {
        // Nothing to wait for: a process that registered no roster seam has no starts of its own
        // in flight, and holding a restore over a question nobody can answer would never end.
        Hilos::$cluster = new ClusterContext();
        Hilos::$cluster->registerProtectedMode($this->switch);

        $this->gate->requestEnter($this->enableData('restore'));

        $this->assertSame(['restore'], $this->switch->entered);
    }

    /**
     * @param string $operation Operation name the freeze protects
     * @return ProtectedModeEnableSignalData Enable request as an initiator agent raises it
     */
    private function enableData(string $operation): ProtectedModeEnableSignalData
    {
        return new ProtectedModeEnableSignalData(
            operation: $operation,
            initiatorAcceptKey: null,
            initiatorSessionTokenHash: null,
            initiatorAgentType: 'backup',
            initiatorAgentIndex: null,
            initiatorNodeId: null,
        );
    }

    /** @return EventLoopTimer Exact native handle of the current gate binding */
    private function timer(): EventLoopTimer
    {
        return new ReflectionProperty(ProtectedModeEntryGate::class, 'timer')->getValue($this->gate);
    }
}

/**
 * Recording fake of the freeze switch: names the operations that reached it, in order.
 */
final class FakeFreezeSwitch implements ProtectedModeSwitch
{
    /** @var list<string> Operations of the enable requests that reached the switch, in order */
    public array $entered = [];

    /** @var ?Closure(ProtectedModeEnableSignalData): void Reentrant external switch effect */
    public ?Closure $afterEnter = null;

    public function requestEnable(ProtectedModeEnableSignalData $data): void
    {
        $this->entered[] = $data->operation;
        ($this->afterEnter)?->__invoke($data);
    }

    public function requestDisable(ProtectedModeDisableSignalData $data): void
    {
    }

    public function requestVerify(ProtectedModeVerifySignalData $data): void
    {
    }

    public function requestProgress(ProtectedModeProgressSignalData $data): void
    {
    }

    public function requestPass(ProtectedModePassSignalData $data): void
    {
    }

    public function requestAdmit(string $passHash, string $sessionTokenHash): void
    {
    }

    public function requestCircle(ProtectedModeCircleSignalData $data): void
    {
    }

    public function requestRefreeze(ProtectedModeRefreezeSignalData $data): void
    {
    }

    public function onRosterStopped(): void
    {
    }

    public function onRosterResumed(): void
    {
    }
}

/**
 * Fake of the roster seam: answers whatever the test says is still starting.
 */
final class FakeRosterFreezer implements ProtectedModeAgentFreezer
{
    /** @var list<string> Ids the node is waiting on start reports for */
    public array $stillStarting = [];

    public int $reads = 0;

    public function stopAgentsForProtectedMode(string $initiatorAgentType, ?string $initiatorAgentIndex): void
    {
    }

    public function resumeAgentsForProtectedMode(): void
    {
    }

    public function agentsStillStarting(): array
    {
        ++$this->reads;
        return $this->stillStarting;
    }
}
