<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Hilos;
use Hilos\Socket\Worker\DaemonConnectionState;
use Hilos\Socket\Worker\WorkerDaemonClient;
use Hilos\Socket\Worker\WorkerReadWake;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Socket;

/** Controlled epoch time proves service wakes without waiting out a production timer. */
final class WorkerManagerServiceWaitTest extends TestCase
{
    /** Release the constructor's process-global router. */
    protected function tearDown(): void
    {
        Hilos::$sr = null;
        SourceInterestRegistry::readsWhatItMounts();
        parent::tearDown();
    }

    /** Multiple parent checks spend one budget and never tick an agent or project hook. */
    public function testParentWakesKeepTheOriginalDeadlineWithoutAgentUnits(): void
    {
        $manager = new WorkerServiceWaitManager();
        $agent = $this->createMock(AgentInterface::class);
        $agent->method('getId')->willReturn('service-wait');
        $agent->expects($this->never())->method('onTick');
        $manager->addAgent($agent);
        $client = new WorkerServiceWaitClient(function (float $seconds) use ($manager): WorkerReadWake {
            $manager->now += $seconds;
            return WorkerReadWake::TIMEOUT;
        });
        $manager->attach($client);

        $manager->wait(2.5);

        $this->assertSame([1.0, 1.0, 0.5], $client->waits);
        $this->assertSame(1002.5, $manager->now);
        $this->assertSame(3, $manager->parentReads);
        $this->assertSame(0, $manager->ticks);
        $this->assertFalse($manager->exitRequested());
    }

    /** Parent loss interrupts a long budget with the transport still connected and no EOF. */
    public function testParentLossExitsInsideTheWait(): void
    {
        $manager = new WorkerServiceWaitManager();
        $client = new WorkerServiceWaitClient(function (float $seconds) use ($manager): WorkerReadWake {
            $manager->now += $seconds;
            $manager->parentPid = 1;
            return WorkerReadWake::TIMEOUT;
        });
        $manager->attach($client);

        $manager->wait(600.0);

        $this->assertSame([1.0], $client->waits);
        $this->assertSame(2, $manager->parentReads);
        $this->assertSame(0, $manager->ticks);
        $this->assertTrue($client->isConnected());
        $this->assertTrue($manager->exitRequested());
    }

    /** Time spent on a service check is subtracted from both the parent and caller deadlines. */
    public function testServiceWorkDoesNotExtendTheBudget(): void
    {
        $manager = new WorkerServiceWaitManager();
        $manager->parentCheckCost = 0.25;
        $client = new WorkerServiceWaitClient(function (float $seconds) use ($manager): WorkerReadWake {
            $manager->now += $seconds;
            return WorkerReadWake::TIMEOUT;
        });
        $manager->attach($client);

        $manager->wait(2.5);

        $this->assertSame([0.75, 0.75, 0.25], $client->waits);
        $this->assertSame(1002.5, $manager->now);
        $this->assertSame(3, $manager->parentReads);
    }

    /** A check from the preceding work pass remains the only parent clock. */
    public function testWaitReusesTheExistingParentCheckMoment(): void
    {
        $manager = new WorkerServiceWaitManager();
        $manager->checkParent();
        $manager->now += 0.75;
        $client = new WorkerServiceWaitClient(function (float $seconds) use ($manager): WorkerReadWake {
            $manager->now += $seconds;
            return WorkerReadWake::TIMEOUT;
        });
        $manager->attach($client);

        $manager->wait(0.5);

        $this->assertSame([0.25, 0.25], $client->waits);
        $this->assertSame(2, $manager->parentReads);
    }

    /** A frame returns control to the work pass instead of continuing a service-only wait. */
    public function testReadinessEndsTheServiceLoop(): void
    {
        $manager = new WorkerServiceWaitManager();
        $client = new WorkerServiceWaitClient(static fn (): WorkerReadWake => WorkerReadWake::READABLE);
        $manager->attach($client);

        $manager->wait(600.0);

        $this->assertSame([1.0], $client->waits);
        $this->assertSame(1, $manager->parentReads);
        $this->assertSame(0, $manager->ticks);
    }

    /** A caller budget already spent on arrival owes no parent check or physical wait. */
    public function testExpiredBudgetDoesNoServiceWork(): void
    {
        $manager = new WorkerServiceWaitManager();
        $client = new WorkerServiceWaitClient(static fn (): WorkerReadWake => WorkerReadWake::TIMEOUT);
        $manager->attach($client);

        $manager->wait(0.0);

        $this->assertSame([], $client->waits);
        $this->assertSame(0, $manager->parentReads);
    }

    /** A timer returning just past the final parent boundary must not start another service check. */
    public function testTimeoutCrossingTheCallerDeadlineStopsBeforeAnotherParentCheck(): void
    {
        $manager = new WorkerServiceWaitManager();
        $client = new WorkerServiceWaitClient(function (float $seconds) use ($manager): WorkerReadWake {
            $manager->now += $seconds + 0.000001;
            return WorkerReadWake::TIMEOUT;
        });
        $manager->attach($client);

        $manager->wait(1.0);

        $this->assertSame([1.0], $client->waits);
        $this->assertSame(1, $manager->parentReads);
        $this->assertSame(1001.000001, $manager->now);
    }

    /** An early native timeout may re-wait only the remainder, with no service work past the caller deadline. */
    public function testEarlyTimeoutRewaitsOnlyTheUnspentFraction(): void
    {
        $manager = new WorkerServiceWaitManager();
        $calls = 0;
        $client = new WorkerServiceWaitClient(function (float $seconds) use ($manager, &$calls): WorkerReadWake {
            $manager->now += $seconds - (++$calls === 1 ? 0.0001 : 0.0);
            return WorkerReadWake::TIMEOUT;
        });
        $manager->attach($client);

        $manager->wait(1.0);

        $this->assertCount(2, $client->waits);
        $this->assertSame(1.0, $client->waits[0]);
        $this->assertEqualsWithDelta(0.0001, $client->waits[1], 1e-10);
        $this->assertSame(1001.0, $manager->now);
        $this->assertSame(1, $manager->parentReads);
    }

    /** @return iterable<string, array{float, list<float>}> Default idle and several parent-only wakes */
    public static function idleCycles(): iterable
    {
        yield 'default' => [0.1, [0.1]];
        yield 'parent-only wakes' => [2.5, [1.0, 1.0, 0.5]];
    }

    /**
     * Observe run() across real work-pass boundaries, including startup and the next
     * pass. Parent-only wakes spend the original budget without pumping or ticking.
     *
     * @param float $allowance Live agent idle permission
     * @param list<float> $expectedWaits Physical slices in the first full idle cycle
     */
    #[DataProvider('idleCycles')]
    public function testOrdinaryIdleCyclesPumpOnceRegardlessOfParentWakes(float $allowance, array $expectedWaits): void
    {
        $manager = new WorkerIdleCycleManager($allowance);
        try {
            $manager->run();

            $this->assertSame([2, 3, 4], $manager->client->pollsAtPass);
            $this->assertSame(3, $manager->client->passes);
            $this->assertCount(count($expectedWaits) * 2, $manager->client->waits);
            foreach ($expectedWaits as $index => $seconds) {
                $this->assertEqualsWithDelta($seconds, $manager->client->waits[$index], 1e-10);
            }
            $this->assertEqualsWithDelta(1000.0 + $allowance * 2, $manager->now, 1e-10);
        } finally {
            $manager->closePair();
        }
    }

    /** The real scheduler still gives idle work 100 ms and active work the 10 ms pause. */
    public function testSchedulerKeepsItsDefaultAndActiveCadences(): void
    {
        $manager = new WorkerServiceWaitManager();
        $pair = [];
        $this->assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        $client = new WorkerServiceWaitClient(static fn (): WorkerReadWake => WorkerReadWake::READABLE);
        $client->adopt($pair[0]);
        $manager->attach($client);

        try {
            $manager->finishPass(1000.0, false);
            $manager->finishPass(1000.0, true);
            $manager->now = 1000.25;
            $manager->finishPass(1000.0, false);

            $this->assertCount(1, $client->waits);
            $this->assertEqualsWithDelta(0.1, $client->waits[0], 1e-10);
            $this->assertSame([10000], $manager->pauses);
        } finally {
            $client->close();
            socket_close($pair[1]);
        }
    }
}

/** Real service loop with a controlled epoch clock and parent identity. */
final class WorkerServiceWaitManager extends WorkerManager
{
    public float $now = 1000.0;
    public int $parentPid = 4242;
    public int $parentReads = 0;
    public float $parentCheckCost = 0.0;
    public int $ticks = 0;

    /** @var list<int> Requested active pause durations */
    public array $pauses = [];

    public function __construct()
    {
        parent::__construct(1);
        $this->daemonPid = $this->parentPid;
    }

    /** @param WorkerDaemonClient $client Scripted wait transport */
    public function attach(WorkerDaemonClient $client): void
    {
        $this->daemonClient = $client;
    }

    /** @param AgentInterface $agent Agent that service-only wakes must never enter */
    public function addAgent(AgentInterface $agent): void
    {
        $this->agentManager->addAgent($agent->getId(), $agent);
    }

    /** @param float $seconds Caller wait budget */
    public function wait(float $seconds): void
    {
        $this->awaitDaemonWork($this->now + $seconds);
    }

    /** Runs the ordinary work pass's parent check on the same clock. */
    public function checkParent(): void
    {
        $this->checkDaemonLiveness($this->now);
    }

    /**
     * @param float $startedAt Work pass start
     * @param bool $handledMessage Active-pass guard
     */
    public function finishPass(float $startedAt, bool $handledMessage): void
    {
        Closure::bind(function () use ($startedAt, $handledMessage): void {
            $this->waitForWork($startedAt, $handledMessage);
        }, $this, WorkerManager::class)();
    }

    /** @return bool Whether the parent detector requested exit */
    public function exitRequested(): bool
    {
        return $this->shouldExit;
    }

    /** @return float Controlled epoch seconds */
    protected function waitTime(): float
    {
        return $this->now;
    }

    /** @return int Scripted supervisor identity, charging any simulated check work */
    protected function currentParentPid(): int
    {
        $this->parentReads++;
        $this->now += $this->parentCheckCost;
        return $this->parentPid;
    }

    /** Counts accidental project ticks. */
    protected function onTick(): void
    {
        $this->ticks++;
    }

    /**
     * @param float $loopStartTime Pass start, unused by the recording pause
     * @param int $targetLoopTimeMicroseconds Requested active cadence
     */
    protected function sleepWithPreciseTiming(float $loopStartTime, int $targetLoopTimeMicroseconds = 10000): void
    {
        $this->pauses[] = $targetLoopTimeMicroseconds;
    }

    /** @return SignalRouter Unused signal router */
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    /** @return AgentManager Holder of agents the service loop must not enter */
    protected function createAgentManager(): AgentManager
    {
        return new class extends AgentManager {
            /**
             * @param string $agentType Unused type
             * @param ?string $agentIndex Unused index
             * @return AgentInterface Never returns
             */
            protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
            {
                throw new RuntimeException('The service wait never creates an agent.');
            }
        };
    }
}

/** Returns prepared events while advancing only the test's controlled clock. */
final class WorkerServiceWaitClient extends WorkerDaemonClient
{
    /** @var list<float> Exact waits requested by the real service loop */
    public array $waits = [];

    /** @param Closure(float): WorkerReadWake $onWait Prepared wake and clock movement */
    public function __construct(private readonly Closure $onWait)
    {
    }

    /** @param Socket $socket Socket presence for the real scheduler guard */
    public function adopt(Socket $socket): void
    {
        $this->socket = $socket;
    }

    /** @return bool Parent checks are independent of transport loss */
    public function isConnected(): bool
    {
        return true;
    }

    /**
     * @param float $timeoutSeconds Requested wait budget
     * @return WorkerReadWake Prepared event outcome
     */
    public function awaitReadable(float $timeoutSeconds): WorkerReadWake
    {
        $this->waits[] = $timeoutSeconds;
        return ($this->onWait)($timeoutSeconds);
    }
}

/** Runs complete ordinary passes with a controlled clock and recording transport. */
final class WorkerIdleCycleManager extends WorkerManager
{
    public float $now = 1000.0;
    public WorkerIdleCycleClient $client;
    private Socket $peer;

    /** @param float $allowance Live permission used by the real scheduler */
    public function __construct(float $allowance)
    {
        parent::__construct(1);
        $pair = [];
        TestCase::assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        TestCase::assertTrue(socket_set_nonblock($pair[0]));
        $this->peer = $pair[1];
        $this->client = new WorkerIdleCycleClient($pair[0], $this);
        $this->daemonClient = $this->client;
        $agent = $allowance === 0.1 ? new WorkerIdleCycleAgent() : new class extends WorkerIdleCycleAgent {
            protected const MAX_IDLE_SLEEP_SECONDS = 2.5;
        };
        $this->agentManager->addAgent($agent->getId(), $agent);
    }

    /** Stops after observing the third work boundary, following two complete idle cycles. */
    public function stopAfterObservation(): void
    {
        $this->shouldExit = true;
    }

    /** Releases the pair even if an assertion or run fails. */
    public function closePair(): void
    {
        $this->client->close();
        socket_close($this->peer);
    }

    /** @return float Controlled epoch seconds */
    protected function waitTime(): float
    {
        return $this->now;
    }

    /** @return int Stable supervisor, independent of the test runner's parent */
    protected function currentParentPid(): int
    {
        return 4242;
    }

    /** Keeps the real run loop without replacing PHPUnit's error handlers. */
    protected function setupErrorHandling(): void
    {
    }

    /** Pump calls are recorded by the client; native signal delivery has its own suite. */
    protected function setupSignalHandlers(): void
    {
    }

    /** The pair is already connected before run starts. */
    protected function connectToDaemon(): void
    {
    }

    /** @return SignalRouter Ordinary queue with no traffic */
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    /** @return AgentManager Roster populated before run starts */
    protected function createAgentManager(): AgentManager
    {
        return new class extends AgentManager {
            /**
             * @param string $agentType Unused type
             * @param ?string $agentIndex Unused index
             * @return AgentInterface Never returns
             */
            protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
            {
                throw new RuntimeException('Idle cycles use an already live agent.');
            }
        };
    }
}

/** Default hooks leave the real worker scheduler free to use the declared idle allowance. */
class WorkerIdleCycleAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'unit_idle_cycle';

    /** No resources belong to this test agent. */
    public function onStop(): void
    {
    }
}

/** Records event-pump requests over full run() cycles, advancing only controlled time. */
final class WorkerIdleCycleClient extends WorkerDaemonClient
{
    public int $polls = 0;
    public int $passes = 0;
    /** @var list<int> Total pump requests at each ordinary read/work boundary */
    public array $pollsAtPass = [];
    /** @var list<float> Physical read-wait budgets */
    public array $waits = [];

    /**
     * @param Socket $socket Connected worker socket
     * @param WorkerIdleCycleManager $manager Owner of the controlled clock
     */
    public function __construct(Socket $socket, private readonly WorkerIdleCycleManager $manager)
    {
        $this->socket = $socket;
        $this->state = DaemonConnectionState::CONNECTED;
    }

    /** Counts nonblocking pump requests, distinct from native waits and mark drains. */
    public function pollProcessSignals(): void
    {
        $this->polls++;
        parent::pollProcessSignals();
    }

    /** Observes each real ordinary pass, stopping after two complete idle cycles. */
    public function read(): void
    {
        $this->pollsAtPass[] = $this->polls;
        if (++$this->passes === 3) {
            $this->manager->stopAfterObservation();
        }
        parent::read();
    }

    /**
     * @param float $timeoutSeconds Physical wait allowance
     * @return WorkerReadWake Timer expiry on the controlled clock
     */
    public function awaitReadable(float $timeoutSeconds): WorkerReadWake
    {
        $this->waits[] = $timeoutSeconds;
        $this->manager->now += $timeoutSeconds;
        return WorkerReadWake::TIMEOUT;
    }
}
