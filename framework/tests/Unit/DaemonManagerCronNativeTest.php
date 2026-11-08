<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Leadership;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\EventLoop\EventLoopTimer;
use Hilos\Hilos;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Socket;

/** Calendar scheduling uses real native readiness; its business still runs only inside the guarded duty. */
final class DaemonManagerCronNativeTest extends TestCase
{
    private EventLoop $loop;
    private DaemonManager $manager;
    private ?ClusterContext $previousCluster;
    private int $runs = 0;

    /** @var list<Socket> Already-readable sockets, never a clock wait */
    private array $sockets = [];

    /** An idle registry and either missing prerequisite sleep; ready and role work in either order. */
    public function testReadyRoleAndLateRegistration(): void
    {
        $leadership = new CronNativeLeadership();
        $context = new ClusterContext();
        $context->registerLeadership($leadership);
        Hilos::$cluster = $context;
        $this->invoke('attachCronEventLoop');
        $this->addRule('ordinary');
        $this->assertNull($this->timer()->getDeadline());
        $this->set('workersReady', true);
        $this->invoke('reconcilePeriodicDutySources');
        $this->assertNull($this->timer()->getDeadline());
        $leadership->leader = true;
        $this->invoke('reconcilePeriodicDutySources');
        $this->invoke('dispatchCronMinute');
        $deadline = $this->timer()->getDeadline();
        $this->assertNotNull($deadline);
        $this->assertSame(0, $this->runs, 'a newly seeded rule never fires immediately');
        $this->assertTrue($this->invoke('removeCronRule', 'ordinary'));
        $this->assertNull($this->timer()->getDeadline());
        $this->addRule('late');
        $this->assertNotNull($this->timer()->getDeadline());
        $this->invoke('dispatchCronMinute');
        $leadership->leader = false;
        $this->invoke('reconcilePeriodicDutySources');
        $this->assertNull($this->timer()->getDeadline());
        $leadership->leader = true;
        $this->set('workersReady', false);
        $this->invoke('reconcilePeriodicDutySources');
        $this->assertNull($this->timer()->getDeadline());
        $this->set('workersReady', true);
        $this->invoke('reconcilePeriodicDutySources');
        $this->assertNotNull($this->timer()->getDeadline());
    }

    /** An aged calendar predicate plus native deadline cues one actual current-minute run, without catch-up. */
    public function testNativeDueOnlyCuesGuardedBusinessAndDeduplicatesTheCurrentMinute(): void
    {
        $this->enabled();
        $this->age();
        $this->invoke('dispatchCronMinute');
        $this->assertSame(0, $this->runs, 'no native cue exists yet');
        $this->loop->runOnce();
        $this->assertSame(0, $this->runs, 'the native callback cannot run cron');
        $this->assertCalendarArm(fn () => $this->invoke('dispatchCronMinute'));
        $this->assertSame(1, $this->runs);
        $this->set('cronMinuteDeadline', hrtime(true) / 1e9 - 1);
        $this->invoke('reconcileCronSchedule');
        $this->loop->runOnce();
        $this->invoke('dispatchCronMinute');
        $this->assertSame(1, $this->runs, 'a repeated check of the same absolute rule minute cannot replay it');

        // A delayed owner sees the current absolute minute once, without catching up missed minutes.
        $this->rules()['ordinary']->now += 3 * TimeConstants::SECONDS_PER_MINUTE;
        $this->set('lastCronCheckMinute', -1);
        $this->set('cronMinuteDeadline', hrtime(true) / 1e9 - 1);
        $this->invoke('reconcileCronSchedule');
        $this->loop->runOnce();
        $this->assertSame(1, $this->runs, 'even a delayed native callback only cues the guarded duty');
        $this->assertCalendarArm(fn () => $this->invoke('dispatchCronMinute'));
        $this->assertSame(2, $this->runs);
        $this->assertSame($this->rules()['ordinary']->now, $this->rules()['ordinary']->lastFiredAt);
        $this->assertFalse($this->loop->hasReadyWork());
    }

    /** @return iterable<string, array{bool}> A still-future boundary or one already missed by the owner */
    public static function calendarBoundaries(): iterable
    {
        yield 'future boundary' => [false];
        yield 'elapsed boundary' => [true];
    }

    /**
     * Unrelated I/O preserves a future basis; an elapsed boundary runs once and rearms before explicit fire.
     *
     * @param bool $elapsed Whether scheduling resumed after the saved boundary
     */
    #[DataProvider('calendarBoundaries')]
    public function testFutureDeadlineSurvivesIoPauseAndForcedFire(bool $elapsed): void
    {
        $this->enabled();
        $rule = $this->rules()['ordinary'];
        // Calendar conversion was checked at arm. Put only this native fixture far ahead or in the past.
        $this->set('cronMinuteDeadline', hrtime(true) / 1e9 + ($elapsed ? -1 : 3600));
        $this->invoke('reconcileCronSchedule');
        $rule->now += TimeConstants::SECONDS_PER_MINUTE;
        $this->set('lastCronCheckMinute', -1);
        $deadline = $this->timer()->getDeadline();
        $this->assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $this->sockets));
        $this->loop->registerRead($this->sockets[0], function (): void {
            socket_read($this->sockets[0], 1);
        });
        socket_write($this->sockets[1], 'x');
        $this->loop->runOnce();
        $this->assertSame(0, $this->runs, 'native I/O and timer callbacks cannot run cron');
        if ($elapsed) {
            $this->assertCalendarArm(fn () => $this->invoke('dispatchCronMinute'));
            $this->assertSame(1, $this->runs, 'the elapsed calendar obligation runs once');
            $this->assertNotSame($deadline, $this->timer()->getDeadline());
            $deadline = $this->timer()->getDeadline();
        } else {
            $this->invoke('dispatchCronMinute');
            $this->assertSame(0, $this->runs);
            $this->assertSame($deadline, $this->timer()->getDeadline());
        }
        $this->invoke('updateCronRule', 'ordinary', '* * * * *');
        $rule->pauseSchedule('first');
        $rule->pauseSchedule('second');
        $rule->fireOnNextCheck();
        $this->invoke('runCronRuleIfDue', $rule);
        $rule->resumeSchedule('first');
        $rule->resumeSchedule('second');
        $this->invoke('reconcilePeriodicDutySources');
        $this->assertSame($elapsed ? 2 : 1, $this->runs);
        $this->assertSame($deadline, $this->timer()->getDeadline());
        $this->invoke('dispatchCronMinute');
        $this->assertSame($elapsed ? 2 : 1, $this->runs);
    }

    /** Role loss cancels activated work; rebind preserves its basis and detach cannot resurrect it. */
    public function testActivatedRoleLossRebindAndDetach(): void
    {
        $this->enabled();
        $this->age();
        $old = $this->timer();
        $activated = false;
        $this->loop->createReadyWork(function () use (&$activated, $old): void {
            $activated = $old->getDeadline() === null;
            $this->set('workersReady', false);
            $this->invoke('reconcileCronSchedule');
        })->markReady();
        $this->loop->runOnce();
        $this->invoke('dispatchCronMinute');
        $this->assertTrue($activated);
        $this->assertSame(0, $this->runs);
        $this->set('workersReady', true);
        $this->invoke('reconcileCronSchedule');
        $deadline = $this->timer()->getDeadline();
        $this->invoke('attachCronEventLoop');
        $this->assertSame($deadline, $this->timer()->getDeadline());
        $this->invoke('detachCronEventLoop');
        $this->invoke('reconcilePeriodicDutySources');
        $this->loop->tick();
        $this->invoke('dispatchCronMinute');
        $this->assertSame(0, $this->runs);
        $this->assertFalse($this->loop->hasReadyWork());
    }

    /** Installs just the existing production scheduler seams on a native base. */
    protected function setUp(): void
    {
        $this->previousCluster = Hilos::$cluster;
        Hilos::$cluster = null;
        $this->loop = new EventLoop();
        $this->manager = $this->getMockBuilder(DaemonManager::class)
            ->onlyMethods(['createSignalRouter', 'createAgentManagerDaemon', 'onCron'])
            ->disableOriginalConstructor()->getMock();
        $this->manager->method('onCron')->willReturnCallback(function (): void {
            ++$this->runs;
        });
        $this->set('eventLoop', $this->loop);
    }

    /** Releases timer owners before the native base. */
    protected function tearDown(): void
    {
        $this->invoke('detachCronEventLoop');
        $this->loop->cleanup();
        foreach ($this->sockets as $socket) {
            socket_close($socket);
        }
        Hilos::$cluster = $this->previousCluster;
        parent::tearDown();
    }

    private function enabled(): void
    {
        $this->set('workersReady', true);
        $this->addRule('ordinary');
        $this->invoke('attachCronEventLoop');
        $this->assertCalendarArm(fn () => $this->invoke('dispatchCronMinute'));
        $this->assertSame(0, $this->runs, 'a seeded rule does not depend on elapsed wall time in the fixture');
    }

    /**
     * Register through the production entrance, then pin only the rule's existing local test clock seam.
     *
     * @param string $name Rule to install
     */
    private function addRule(string $name): void
    {
        $this->invoke('addCronRule', $name, '* * * * *');
        $rule = new CronNativeRule($name, '* * * * *');
        $rule->now = strtotime('2026-11-08 12:34:59 UTC');
        $rule->lastRun = floor($rule->now / TimeConstants::SECONDS_PER_MINUTE);
        $this->set('cronRules', array_replace($this->rules(), [$name => $rule]));
    }

    /**
     * Observe the same arm, including preemption across any calendar boundary, without a new relative-now oracle.
     *
     * @param callable(): mixed $arm Guarded action that converts and arms the next calendar boundary
     */
    private function assertCalendarArm(callable $arm): void
    {
        $beforeMonotonic = hrtime(true) / 1e9;
        $beforeEpoch = microtime(true);
        $arm();
        $afterEpoch = microtime(true);
        $afterMonotonic = hrtime(true) / 1e9;
        $deadline = $this->timer()->getDeadline();
        $this->assertNotNull($deadline);
        $this->assertGreaterThanOrEqual(
            $beforeMonotonic + (floor($beforeEpoch / 60) + 1) * 60 - $afterEpoch,
            $deadline,
            'the selected calendar boundary cannot precede this arm observation',
        );
        $this->assertLessThanOrEqual(
            $afterMonotonic + (floor($afterEpoch / 60) + 1) * 60 - $beforeEpoch,
            $deadline,
            'the selected calendar boundary cannot follow this arm observation',
        );
    }

    /** Ages the manager and rule guards before arming an overdue native deadline. */
    private function age(): void
    {
        $this->set('lastCronCheckMinute', -1);
        $this->rules()['ordinary']->lastRun = floor($this->rules()['ordinary']->now / TimeConstants::SECONDS_PER_MINUTE) - 2;
        $this->set('cronMinuteDeadline', hrtime(true) / 1e9 - 1);
        $this->invoke('reconcileCronSchedule');
    }

    /** @return array<string, CronNativeRule> Live registry with rule-local test clocks */
    private function rules(): array
    {
        return new ReflectionProperty(DaemonManager::class, 'cronRules')->getValue($this->manager);
    }

    /** @return EventLoopTimer Actual bound calendar timer */
    private function timer(): EventLoopTimer
    {
        return new ReflectionProperty(DaemonManager::class, 'cronMinuteTimer')->getValue($this->manager);
    }

    /**
     * @param string $property Scheduler or readiness fact to prepare
     * @param mixed $value Concrete local basis
     */
    private function set(string $property, mixed $value): void
    {
        new ReflectionProperty(DaemonManager::class, $property)->setValue($this->manager, $value);
    }

    /**
     * @param string $method Guarded duty or causal mutation
     * @param mixed ...$arguments Mutation arguments
     * @return mixed Production seam's result
     */
    private function invoke(string $method, mixed ...$arguments): mixed
    {
        return new ReflectionMethod(DaemonManager::class, $method)->invoke($this->manager, ...$arguments);
    }
}

/** Fixes only the rule check's moment; the manager and its native timer keep their production clocks. */
final class CronNativeRule extends CronRule
{
    public int $now;

    /** @return int The scripted absolute moment of this rule's current check */
    protected function currentTimestamp(): int
    {
        return $this->now;
    }
}

/** A role fact, without a second coordinator or replacement clock. */
final class CronNativeLeadership implements Leadership
{
    public bool $leader = false;

    /** @return bool Current role fact */
    public function amLeader(): bool
    {
        return $this->leader;
    }

    /** @return ?string No remote leader needed for the local duty */
    public function leaderId(): ?string
    {
        return null;
    }

    /** @return bool No browser admission in this fixture */
    public function leaderWebSocketOpen(): bool
    {
        return false;
    }

    /** @return bool No quorum needed in this local scheduling fixture */
    public function hasQuorum(): bool
    {
        return false;
    }
}
