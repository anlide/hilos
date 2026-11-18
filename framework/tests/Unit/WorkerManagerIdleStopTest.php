<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Hilos\Constants\AgentConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Auth\AccountOperation\Testing\AccountErasureProbeAction;
use Hilos\Auth\AccountOperation\Testing\AccountErasureProbeRequest;
use Hilos\Environment\EnvAccessor;
use Hilos\Socket\Worker\DTO\WorkerTestAccountErasureProbeDTO;
use Hilos\Socket\Worker\DTO\WorkerTestAccountErasureProbedDTO;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentIdleTracker;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Hilos;
use Hilos\Hilos as HilosFacade;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Worker\WorkerDTO;
use Hilos\Socket\Worker\DTO\AgentStartDTO;
use Hilos\Socket\Worker\WorkerDaemonClient;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * An instance agent nobody has addressed for its declared window leaves by the self-stop path.
 *
 * The whole point of the policy is that no second way to die is invented: onStop() runs, the
 * truth-source grants come back in the hook's finally, the analytics session closes and the
 * daemon is told - exactly what the agent's own selfStop() produces. Each test below is one of
 * the four things that have to agree before the stop is taken, so a regression in any of them
 * shows up as a named case rather than as a process count nobody watches.
 */
final class WorkerManagerIdleStopTest extends TestCase
{
    private const string IDLE_TYPE = 'unit_idle_stop_agent';

    private const string FOREVER_TYPE = 'unit_lives_forever_agent';

    private const string IDLE_AGENT_ID = self::IDLE_TYPE . ':7';

    private const string FOREVER_AGENT_ID = self::FOREVER_TYPE . ':7';

    private const int WINDOW_SEC = 240;

    /** @var class-string<Hilos> App class bound before this test touched it */
    private string $boundAppClass;

    protected function setUp(): void
    {
        $this->boundAppClass = Hilos::appClass();
        $this->bindAppClass(IdleStopTestHilos::class);
    }

    protected function tearDown(): void
    {
        $this->bindAppClass($this->boundAppClass);
        TruthSourceRegistry::unregisterAgent(self::IDLE_AGENT_ID);
        TruthSourceRegistry::unregisterAgent(self::FOREVER_AGENT_ID);
        Hilos::$rt = null;
        Hilos::$sr = null;

        parent::tearDown();
    }

    /** The control ages one known personal source; only the normal tick may decide its exit. */
    public function testExpireIdleAgesOnlyTheDeclaredLocalPersonalSourceAndUsesOrdinaryGuards(): void
    {
        $previousEnv = Hilos::$env;
        putenv('APP_ENV=test');
        Hilos::$env = new EnvAccessor();
        try {
            $this->bindAppClass(IdleStopProbeTestHilos::class);
            $manager = new IdleStopTestManager();
            $manager->clock = 1000.0;
            $personalId = HilosAgentType::HILOS_USER . ':7';
            $personal = $this->addAgent($manager, HilosAgentType::HILOS_USER);
            $this->addAgent($manager, self::IDLE_TYPE);
            $manager->agentManagerDouble->createAndAddAgent(HilosAgentType::HILOS_USER, '0');
            $times = new ReflectionProperty(AgentIdleTracker::class, 'lastEventAt');
            $before = $times->getValue($this->tracker($manager));
            foreach ([HilosAgentType::HILOS_USER . ':99', HilosAgentType::HILOS_USER . ':0', self::IDLE_AGENT_ID] as $invalid) {
                $manager->handleDaemonMessage(new WorkerTestAccountErasureProbeDTO('invalid',
                    new AccountErasureProbeRequest($invalid, AccountErasureProbeAction::ExpireIdle)));
                $ack = $manager->daemonClientDouble->sent[array_key_last($manager->daemonClientDouble->sent)];
                self::assertInstanceOf(WorkerTestAccountErasureProbedDTO::class, $ack);
                self::assertFalse($ack->ok);
                self::assertSame($before, $times->getValue($this->tracker($manager)), 'A refusal cannot create or age a tracker key');
            }
            $this->bindAppClass(IdleStopProbeNoIdleHilos::class);
            $manager->handleDaemonMessage(new WorkerTestAccountErasureProbeDTO('undeclared',
                new AccountErasureProbeRequest($personalId, AccountErasureProbeAction::ExpireIdle)));
            self::assertFalse($manager->daemonClientDouble->sent[array_key_last($manager->daemonClientDouble->sent)]->ok);
            self::assertSame($before, $times->getValue($this->tracker($manager)));
            $this->bindAppClass(IdleStopProbeTestHilos::class);
            $this->tracker($manager)->noteSubscriber($personalId, 'probe-tab', 1000.0);
            $manager->handleDaemonMessage(new WorkerTestAccountErasureProbeDTO('expire',
                new AccountErasureProbeRequest($personalId, AccountErasureProbeAction::ExpireIdle)));
            $ack = $manager->daemonClientDouble->sent[array_key_last($manager->daemonClientDouble->sent)];
            self::assertTrue($ack->ok);
            self::assertSame(['armed' => false, 'matched' => 0, 'held' => null], $ack->state->toArray());
            self::assertSame(760.0, $times->getValue($this->tracker($manager))[$personalId], 'The control uses the ordinary waitTime clock');
            self::assertFalse($personal->stopHookRan, 'The control itself stops nobody');
            $this->tickAgents($manager);
            self::assertTrue($manager->hasAgent($personalId), 'The subscriber still holds the source');
            $this->tracker($manager)->dropSubscriber($personalId, 'probe-tab', 1000.0);
            $manager->handleDaemonMessage(new WorkerTestAccountErasureProbeDTO('expire-again',
                new AccountErasureProbeRequest($personalId, AccountErasureProbeAction::ExpireIdle)));
            $personal->workInFlight = true;
            $this->tickAgents($manager);
            self::assertTrue($manager->hasAgent($personalId), 'In-flight work still holds the source');
            $personal->workInFlight = false;
            $this->freeze(StateProtectedModeRuntime::PHASE_ACTIVE);
            $this->tickAgents($manager);
            self::assertTrue($manager->hasAgent($personalId), 'A frozen node still holds the source');
            Hilos::$rt = null;
            $this->tickAgents($manager);
            self::assertFalse($manager->hasAgent($personalId));
            self::assertTrue($personal->stopHookRan);
            self::assertSame([$personalId], $manager->daemonClientDouble->stoppedAgentIds());
        } finally {
            Hilos::$env = $previousEnv;
        }
    }

    public function testAnAgentSilentPastItsWindowStopsByTheSelfStopPath(): void
    {
        $manager = new IdleStopTestManager();
        $agent = $this->addAgent($manager, self::IDLE_TYPE);
        TruthSourceRegistry::registerCreate(IdleStopTestHilos::TEST_COLLECTION, self::IDLE_AGENT_ID);
        $this->goIdle($manager, self::IDLE_AGENT_ID);

        $this->tickAgents($manager);

        $this->assertTrue($agent->stopHookRan, 'onStop() must run: the idle stop is the ordinary stop.');
        $this->assertFalse($manager->hasAgent(self::IDLE_AGENT_ID), 'The stopped agent must leave the manager.');
        $this->assertFalse(
            TruthSourceRegistry::hasCreateSource(IdleStopTestHilos::TEST_COLLECTION),
            'The truth-source grant must come back, exactly as it does on a self-requested stop.',
        );
        $this->assertSame([self::IDLE_AGENT_ID], $manager->daemonClientDouble->stoppedAgentIds());
    }

    public function testALiveSubscriberKeepsTheAgentUp(): void
    {
        $manager = new IdleStopTestManager();
        $this->addAgent($manager, self::IDLE_TYPE);
        $this->goIdle($manager, self::IDLE_AGENT_ID);
        $this->tracker($manager)->noteSubscriber(self::IDLE_AGENT_ID, 'accept-1', microtime(true));

        $this->tickAgents($manager);

        // An open tab is the only claim no frame renews, and the owner of the instance is the
        // one process that can push someone else's write to it.
        $this->assertTrue($manager->hasAgent(self::IDLE_AGENT_ID));
    }

    public function testStartRestoresSubscribersAlreadyInTheWorkerMirror(): void
    {
        $manager = new IdleStopTestManager();
        Hilos::$sr->subscribeToPage('unit-page', new WebSocketPageSubscribeSignalDTO('accept-1'));
        Hilos::$sr->bindPageAgent('accept-1', 'unit-page', self::IDLE_TYPE, '7');

        $manager->handleDaemonMessage(new AgentStartDTO(self::IDLE_AGENT_ID));
        $this->goIdle($manager, self::IDLE_AGENT_ID);
        $this->tickAgents($manager);

        $this->assertTrue($manager->hasAgent(self::IDLE_AGENT_ID));
    }

    public function testLocalStopDropsThePageRouterOfTheStoppedInstance(): void
    {
        $manager = new IdleStopTestManager();
        $this->addAgent($manager, self::IDLE_TYPE);
        $routers = new ReflectionProperty(WorkerManager::class, 'pageSignalRouters');
        $routers->setValue($manager, [
            self::IDLE_AGENT_ID => new ReflectionClass(PageSignalRouter::class)->newInstanceWithoutConstructor(),
        ]);
        $this->goIdle($manager, self::IDLE_AGENT_ID);

        $this->tickAgents($manager);

        $this->assertSame([], $routers->getValue($manager));
    }

    public function testAnAgentThatClaimsWorkInFlightKeepsItselfUp(): void
    {
        $manager = new IdleStopTestManager();
        $agent = $this->addAgent($manager, self::IDLE_TYPE);
        $agent->workInFlight = true;
        $this->goIdle($manager, self::IDLE_AGENT_ID);

        $this->tickAgents($manager);

        $this->assertTrue($manager->hasAgent(self::IDLE_AGENT_ID));
        $this->assertFalse($agent->stopHookRan);
    }

    public function testAFrozenNodeStopsNobodyForIdleness(): void
    {
        $manager = new IdleStopTestManager();
        $this->addAgent($manager, self::IDLE_TYPE);
        $this->goIdle($manager, self::IDLE_AGENT_ID);
        $this->freeze(StateProtectedModeRuntime::PHASE_ACTIVE);

        $this->tickAgents($manager);

        // The freeze has already refused starts, so an agent stopped under it is one nothing
        // can bring back until the freeze lifts - the opposite of what the freeze promised.
        $this->assertTrue($manager->hasAgent(self::IDLE_AGENT_ID));
    }

    public function testTheVerificationWindowLetsIdleStopsResume(): void
    {
        $manager = new IdleStopTestManager();
        $this->addAgent($manager, self::IDLE_TYPE);
        $this->goIdle($manager, self::IDLE_AGENT_ID);
        $this->freeze(StateProtectedModeRuntime::PHASE_VERIFYING);

        $this->tickAgents($manager);

        // The same phase that reopens starts reopens stops: agents run normally again.
        $this->assertFalse($manager->hasAgent(self::IDLE_AGENT_ID));
    }

    public function testAnAgentWithNoDeclaredWindowNeverStopsForIdleness(): void
    {
        $manager = new IdleStopTestManager();
        $this->addAgent($manager, self::FOREVER_TYPE);
        $this->goIdle($manager, self::FOREVER_AGENT_ID);

        $this->tickAgents($manager);

        // No key, no policy: this is every agent that existed before the window arrived.
        $this->assertTrue($manager->hasAgent(self::FOREVER_AGENT_ID));
    }

    public function testASelfRequestedStopStillReportsItselfAsSelfRequested(): void
    {
        $manager = new IdleStopTestManager();
        $agent = $this->addAgent($manager, self::IDLE_TYPE);
        $agent->askToStop();
        $this->goIdle($manager, self::IDLE_AGENT_ID);

        $this->tickAgents($manager);

        // Both reasons now share one exit, so the branch order is what keeps the two apart.
        $this->assertTrue($agent->stopHookRan);
        $this->assertFalse($manager->hasAgent(self::IDLE_AGENT_ID));
    }

    /**
     * Registers one agent of the given type on the worker, the way an agent start leaves it.
     *
     * @param IdleStopTestManager $manager Manager under test
     * @param string $agentType Agent type to create
     * @return IdleStopTestAgent The created agent
     */
    private function addAgent(IdleStopTestManager $manager, string $agentType): IdleStopTestAgent
    {
        $agent = $manager->agentManagerDouble->createAndAddAgent($agentType, '7');
        $this->assertInstanceOf(IdleStopTestAgent::class, $agent);
        $this->tracker($manager)->noteStarted($agent->getId(), microtime(true));

        return $agent;
    }

    /**
     * Backdates the agent's last event past its window.
     *
     * @param IdleStopTestManager $manager Manager under test
     * @param string $agentId Agent to backdate
     */
    private function goIdle(IdleStopTestManager $manager, string $agentId): void
    {
        $this->tracker($manager)->noteAddressed($agentId, microtime(true) - (self::WINDOW_SEC + 1));
    }

    /**
     * @param IdleStopTestManager $manager Manager under test
     * @return AgentIdleTracker The manager's own tracker, which is what the decision reads
     */
    private function tracker(IdleStopTestManager $manager): AgentIdleTracker
    {
        $tracker = new ReflectionProperty(WorkerManager::class, 'agentIdleTracker')->getValue($manager);
        $this->assertInstanceOf(AgentIdleTracker::class, $tracker);

        return $tracker;
    }

    /**
     * Runs one agent tick pass, which is where the stop decision is taken.
     *
     * @param WorkerManager $manager Manager under test
     */
    private function tickAgents(WorkerManager $manager): void
    {
        $tick = Closure::bind(
            static function (WorkerManager $manager): void {
                $manager->tickAgents();
            },
            null,
            WorkerManager::class,
        );

        $tick($manager);
    }

    /**
     * Mounts the freeze row in the given phase.
     *
     * @param string $phase Freeze phase to mount
     */
    private function freeze(string $phase): void
    {
        Hilos::$rt = new IdleStopTestRtContext();
        Hilos::$rt->mountFeatureItem(StateProtectedModeRuntime::RT_ITEM, StateProtectedModeRuntime::fromRow([
            StateProtectedModeRuntime::phase => $phase,
            StateProtectedModeRuntime::passHashes => [],
            StateProtectedModeRuntime::admittedSessionTokenHashes => [],
            StateProtectedModeRuntime::circleSessionTokenHashes => [],
            StateProtectedModeRuntime::circleNamedCount => 0,
            StateProtectedModeRuntime::enteredFromBrowser => false,
        ]));
    }

    /**
     * @param class-string<Hilos> $hilosClass Project facade class the agent registry is read from
     */
    private function bindAppClass(string $hilosClass): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $hilosClass);
    }
}

/**
 * Project facade whose registry declares one agent with an idle window and one without.
 *
 * Abstract because only its registry constant is read: the decision never builds a database.
 */
abstract class IdleStopTestHilos extends HilosFacade
{
    public const string TEST_COLLECTION = 'unit_idle_stop_rows';

    public const array AGENTS = [
        IdleStopTestAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => IdleStopTestAgent::class,
            AgentRegistryKey::DAEMON => IdleStopTestAgentDaemon::class,
            AgentRegistryKey::INDEXED => true,
            AgentRegistryKey::IDLE_TIMEOUT => 240,
        ],
        IdleStopTestForeverAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => IdleStopTestForeverAgent::class,
            AgentRegistryKey::DAEMON => IdleStopTestForeverAgentDaemon::class,
            AgentRegistryKey::INDEXED => true,
        ],
    ];
}

abstract class IdleStopProbeTestHilos extends IdleStopTestHilos
{
    public const array AGENTS = parent::AGENTS + [
        HilosAgentType::HILOS_USER => [AgentRegistryKey::IDLE_TIMEOUT => 240],
    ];
}

abstract class IdleStopProbeNoIdleHilos extends IdleStopTestHilos
{
    public const array AGENTS = parent::AGENTS + [HilosAgentType::HILOS_USER => []];
}

class IdleStopTestAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'unit_idle_stop_agent';

    /** Whether the agent claims work no frame and no subscriber account for. */
    public bool $workInFlight = false;

    /** Whether the stop hook has run, which is what proves the ordinary exit was taken. */
    public bool $stopHookRan = false;

    /**
     * @param string $agentIndex Instance this agent owns
     */
    public function __construct(string $agentIndex)
    {
        $this->agentIndex = $agentIndex;
    }

    public function hasWorkInFlight(): bool
    {
        return $this->workInFlight;
    }

    /**
     * Requests the ordinary self-stop, so the test can drive the branch it shares.
     */
    public function askToStop(): void
    {
        $this->selfStop();
    }

    public function onStop(): void
    {
        $this->stopHookRan = true;
    }
}

final class IdleStopPersonalAgent extends IdleStopTestAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_USER;
}

final class IdleStopTestForeverAgent extends IdleStopTestAgent
{
    public const string AGENT_TYPE = 'unit_lives_forever_agent';
}

final class IdleStopTestAgentDaemon extends TopologyTestAgentDaemon
{
    public const string AGENT_TYPE = 'unit_idle_stop_agent';
}

final class IdleStopTestForeverAgentDaemon extends TopologyTestAgentDaemon
{
    public const string AGENT_TYPE = 'unit_lives_forever_agent';
}

/**
 * Runtime context that registers no project state: the framework mount supplies the freeze row.
 */
final class IdleStopTestRtContext extends RtContext
{
    public function configure(): void
    {
    }
}

final class IdleStopTestManager extends WorkerManager
{
    public readonly IdleStopTestDaemonClient $daemonClientDouble;

    public IdleStopTestAgentManager $agentManagerDouble;

    /** Optional clock of the focused expiry case, shared with the ordinary idle verdict. */
    public ?float $clock = null;

    public function __construct()
    {
        parent::__construct(1);
        $this->daemonClientDouble = new IdleStopTestDaemonClient();
        $this->daemonClient = $this->daemonClientDouble;
    }

    /**
     * @param string $agentId Agent id to look for
     * @return bool Whether the worker still holds that agent
     */
    public function hasAgent(string $agentId): bool
    {
        return $this->agentManagerDouble->hasAgent($agentId);
    }

    /** @return float Focused case clock, or the ordinary worker clock */
    protected function waitTime(): float
    {
        return $this->clock ?? parent::waitTime();
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    protected function createAgentManager(): AgentManager
    {
        $this->agentManagerDouble = new IdleStopTestAgentManager();

        return $this->agentManagerDouble;
    }
}

final class IdleStopTestAgentManager extends AgentManager
{
    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        return match ($agentType) {
            IdleStopTestForeverAgent::AGENT_TYPE => new IdleStopTestForeverAgent((string)$agentIndex),
            HilosAgentType::HILOS_USER => new IdleStopPersonalAgent((string)$agentIndex),
            default => new IdleStopTestAgent((string)$agentIndex),
        };
    }
}

final class IdleStopTestDaemonClient extends WorkerDaemonClient
{
    /** @var list<WorkerDTO|array<string, mixed>> Everything the worker handed to the daemon */
    public array $sent = [];

    public function __construct()
    {
    }

    public function isConnected(): bool
    {
        return true;
    }

    /**
     * @param WorkerDTO|array<string, mixed> $data Message the worker wants delivered
     */
    public function send(WorkerDTO|array $data): void
    {
        $this->sent[] = $data;
    }

    /**
     * @return list<string> Agent ids the worker reported as stopped, in order
     */
    public function stoppedAgentIds(): array
    {
        $stopped = [];
        foreach ($this->sent as $message) {
            if (is_array($message) && ($message[WorkerDTO::TYPE] ?? null) === WorkerConstants::MESSAGE_AGENT_STOPPED) {
                $stopped[] = (string)$message[AgentConstants::FIELD_AGENT_ID];
            }
        }

        return $stopped;
    }
}
