<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Daemon\AgentStartSink;
use Hilos\Core\Agent\DTO\AgentMessageDTOInterface;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Agent\Exception\NoSuitableWorkerException;
use Hilos\Core\Daemon\ContainedFailure;
use Hilos\Core\Daemon\ContainedFailureSink;
use Hilos\Core\Daemon\Master\MasterFailureUnit;
use Hilos\Hilos;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Server\AwaitingWorkerAgent;
use Hilos\Socket\Server\WorkerServer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * The monopolistic worker pool grows on demand instead of refusing the agent it ran out for (HIL-998).
 *
 * A monopolistic agent that finds no free worker orders one and waits for it; the next master
 * pass that finds a free monopolistic worker seats it, and a wait that outlives its deadline ends
 * as the refusal a shortage used to be at once. Everything else that found no worker - a regular
 * agent, a node on its way out, a per-instance monopolistic agent - is refused exactly as before.
 */
final class WorkerServerPoolGrowthTest extends TestCase
{
    private const string MONOPOLISTIC_TYPE = 'lonely';

    private const string SECOND_MONOPOLISTIC_TYPE = 'solitary';

    private const string REGULAR_TYPE = 'crowded';

    private const string INITIATOR_TYPE = 'initiator';

    private const string AGENT_INDEX = '1';

    private const int FIRST_WORKER_INDEX = 17;

    private const int SECOND_WORKER_INDEX = 18;

    private const int FRAME_COUNT = 10;

    /** @var class-string<Hilos> App class bound before this test touched it */
    private string $boundAppClass;

    protected function setUp(): void
    {
        $this->boundAppClass = Hilos::appClass();
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, PoolGrowthTestHilos::class);
        Hilos::$cluster = null;
        Hilos::$rt = null;
    }

    protected function tearDown(): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->boundAppClass);

        parent::tearDown();
    }

    /**
     * The one case that is the whole leaf: a full pool orders a worker instead of throwing.
     */
    public function testAnExhaustedPoolOrdersAWorkerAndTheAgentWaitsForIt(): void
    {
        $manager = new PoolGrowthTestAgentManagerDaemon();
        $server = $this->buildServer($manager);

        $server->startAgentPublic(self::MONOPOLISTIC_TYPE);

        $this->assertSame([true], $server->orderedWorkers);
        $this->assertTrue($server->isAgentAwaitingWorker(self::MONOPOLISTIC_TYPE));
        $this->assertTrue($manager->hasAgent(self::MONOPOLISTIC_TYPE));
        $this->assertSame([self::MONOPOLISTIC_TYPE], $server->agentsStillStarting());
    }

    public function testAFreeMonopolisticWorkerSeatsTheWaitingAgentOnTheNextPass(): void
    {
        $manager = new PoolGrowthTestAgentManagerDaemon();
        $server = $this->buildServer($manager);
        $server->startAgentPublic(self::MONOPOLISTIC_TYPE);

        $worker = $server->addWorker(self::FIRST_WORKER_INDEX);
        $server->advancePublic();

        $this->assertFalse($server->isAgentAwaitingWorker(self::MONOPOLISTIC_TYPE));
        $this->assertSame([self::MONOPOLISTIC_TYPE], $worker->startedAgentTypes);
        $this->assertSame(-self::FIRST_WORKER_INDEX, $manager->getAgentWorkerId(self::MONOPOLISTIC_TYPE));
    }

    public function testOneWorkerSeatsOneAgentAndTheOtherKeepsWaiting(): void
    {
        $manager = new PoolGrowthTestAgentManagerDaemon();
        $server = $this->buildServer($manager);
        $server->startAgentPublic(self::MONOPOLISTIC_TYPE);
        $server->startAgentPublic(self::SECOND_MONOPOLISTIC_TYPE);

        $worker = $server->addWorker(self::FIRST_WORKER_INDEX);
        $server->advancePublic();

        $this->assertSame([self::MONOPOLISTIC_TYPE], $worker->startedAgentTypes);
        $this->assertFalse($server->isAgentAwaitingWorker(self::MONOPOLISTIC_TYPE));
        $this->assertTrue($server->isAgentAwaitingWorker(self::SECOND_MONOPOLISTIC_TYPE));

        $second = $server->addWorker(self::SECOND_WORKER_INDEX);
        $server->advancePublic();

        $this->assertSame([self::SECOND_MONOPOLISTIC_TYPE], $second->startedAgentTypes);
        $this->assertFalse($server->isAgentAwaitingWorker(self::SECOND_MONOPOLISTIC_TYPE));
    }

    public function testManyFramesForOneAgentOrderOneWorkerAndNoneIsRefused(): void
    {
        $server = $this->buildServer(new PoolGrowthTestAgentManagerDaemon());

        for ($frame = 0; $frame < self::FRAME_COUNT; $frame++) {
            $server->startAgentPublic(self::MONOPOLISTIC_TYPE);
        }

        $this->assertSame([true], $server->orderedWorkers);
    }

    public function testAWaitPastItsDeadlineIsRefusedLeavingNoRecordAndOneCard(): void
    {
        $manager = new PoolGrowthTestAgentManagerDaemon();
        $startSink = new PoolGrowthTestStartSink();
        $manager->registerAgentStartSink($startSink);
        $server = $this->buildServer($manager);
        $failureSink = new PoolGrowthTestFailureSink();
        $server->setContainedFailureSink($failureSink);
        $server->startAgentPublic(self::MONOPOLISTIC_TYPE);

        $server->expireWaits();
        $server->advancePublic();

        $this->assertFalse($server->isAgentAwaitingWorker(self::MONOPOLISTIC_TYPE));
        $this->assertFalse($manager->hasAgent(self::MONOPOLISTIC_TYPE));
        $this->assertSame([], $server->agentsStillStarting());
        $this->assertCount(1, $failureSink->reported);
        $this->assertSame(MasterFailureUnit::AGENT_START, $failureSink->reported[0]->unit);
        $this->assertSame(self::MONOPOLISTIC_TYPE, $failureSink->reported[0]->address);
        $this->assertInstanceOf(NoSuitableWorkerException::class, $failureSink->reported[0]->failure);
        $this->assertSame([self::MONOPOLISTIC_TYPE], $startSink->failedAgentIds);

        // Nothing is left to refuse twice
        $server->advancePublic();
        $this->assertCount(1, $failureSink->reported);
    }

    public function testARegularAgentWithNoWorkerIsStillRefused(): void
    {
        $manager = new PoolGrowthTestAgentManagerDaemon();
        $server = $this->buildServer($manager);

        $this->assertRefused($server, self::REGULAR_TYPE, null);
        $this->assertFalse($manager->hasAgent(self::REGULAR_TYPE));
    }

    public function testANodeOnItsWayOutOrdersNothing(): void
    {
        $server = $this->buildServer(new PoolGrowthTestAgentManagerDaemon());
        $server->markPreparingShutdown();

        $this->assertRefused($server, self::MONOPOLISTIC_TYPE, null);
    }

    public function testAPerInstanceMonopolisticAgentDoesNotGrowThePool(): void
    {
        $server = $this->buildServer(new PoolGrowthTestAgentManagerDaemon());

        $this->assertRefused($server, self::MONOPOLISTIC_TYPE, self::AGENT_INDEX);
    }

    /**
     * A waiting agent has no worker to be stopped on: the freeze takes it out of the wait and its
     * record with it, and the walk asks no worker to stop it.
     */
    public function testAFreezeTakesAWaitingAgentOutOfTheWait(): void
    {
        $manager = new PoolGrowthTestAgentManagerDaemon();
        $startSink = new PoolGrowthTestStartSink();
        $manager->registerAgentStartSink($startSink);
        $server = $this->buildServer($manager);
        $server->startAgentPublic(self::MONOPOLISTIC_TYPE);

        $server->stopAgentsForProtectedMode(self::INITIATOR_TYPE, null);
        $server->advanceRosterPublic();

        $this->assertFalse($server->isAgentAwaitingWorker(self::MONOPOLISTIC_TYPE));
        $this->assertFalse($manager->hasAgent(self::MONOPOLISTIC_TYPE));
        $this->assertSame([], $server->getProtectedModeStoppedAgents());
        // Said out loud, because the frames the master holds for a starting agent end on a word
        // and on nothing else since HIL-1040: a wait that stopped being silently would leave a
        // page loading for as long as the process lives.
        $this->assertSame([self::MONOPOLISTIC_TYPE], $startSink->failedAgentIds);

        // A worker registering after the freeze is not handed the agent the freeze took out
        $worker = $server->addWorker(self::FIRST_WORKER_INDEX);
        $server->advancePublic();
        $this->assertSame([], $worker->startedAgentTypes);
    }

    /**
     * A placement accepted while the worker is raised answers null, and the worker once seated.
     */
    public function testAPlacementWaitingForAWorkerIsAcceptedAndNamesTheWorkerOnceSeated(): void
    {
        $server = $this->buildServer(new PoolGrowthTestAgentManagerDaemon());

        $this->assertNull($server->executePlacement(self::MONOPOLISTIC_TYPE, null));
        $this->assertNull($server->placedWorkerId(self::MONOPOLISTIC_TYPE, null));

        $server->addWorker(self::FIRST_WORKER_INDEX);
        $server->advancePublic();

        $this->assertSame(-self::FIRST_WORKER_INDEX, $server->placedWorkerId(self::MONOPOLISTIC_TYPE, null));
    }

    public function testAPlacementOfARegularAgentWithNoWorkerIsStillRefused(): void
    {
        $server = $this->buildServer(new PoolGrowthTestAgentManagerDaemon());

        $this->expectException(NoSuitableWorkerException::class);
        $server->executePlacement(self::REGULAR_TYPE, null);
    }

    /**
     * A stop reaching an agent that still waits takes it out of the wait: no worker is asked, and
     * the worker that registers later is not handed an agent nobody wants any more.
     */
    public function testAStopTakesAWaitingAgentOutOfTheWait(): void
    {
        $manager = new PoolGrowthTestAgentManagerDaemon();
        $startSink = new PoolGrowthTestStartSink();
        $manager->registerAgentStartSink($startSink);
        $server = $this->buildServer($manager);
        $server->executePlacement(self::MONOPOLISTIC_TYPE, null);

        $server->revokePlacement(self::MONOPOLISTIC_TYPE, null);

        $this->assertFalse($server->isAgentAwaitingWorker(self::MONOPOLISTIC_TYPE));
        $this->assertFalse($manager->hasAgent(self::MONOPOLISTIC_TYPE));
        // Told for the same reason the freeze tells: a frame held for this agent is waiting on
        // exactly this word and has no clock left behind it (HIL-1040).
        $this->assertSame([self::MONOPOLISTIC_TYPE], $startSink->failedAgentIds);
        $worker = $server->addWorker(self::FIRST_WORKER_INDEX);
        $server->advancePublic();
        $this->assertSame([], $worker->startedAgentTypes);
    }

    /**
     * @param PoolGrowthTestWorkerServer $server Server under test
     * @param string $agentType Agent type expected to be refused
     * @param ?string $agentIndex Agent index expected to be refused
     */
    private function assertRefused(PoolGrowthTestWorkerServer $server, string $agentType, ?string $agentIndex): void
    {
        try {
            $server->startAgentPublic($agentType, $agentIndex);
            $this->fail('No worker is registered and the pool may not grow, so the start was expected to be refused.');
        } catch (NoSuitableWorkerException) {
            $this->assertSame([], $server->orderedWorkers);
            $this->assertSame([], $server->agentsStillStarting());
        }
    }

    /**
     * @param AgentManagerDaemon $manager Agent manager the server routes starts through
     * @return PoolGrowthTestWorkerServer Server holding that manager and no worker
     */
    private function buildServer(AgentManagerDaemon $manager): PoolGrowthTestWorkerServer
    {
        // Skip the constructor: it reads worker env and creates a log directory that this test
        // does not exercise. Only the agent manager is needed.
        $server = new ReflectionClass(PoolGrowthTestWorkerServer::class)->newInstanceWithoutConstructor();
        new ReflectionProperty(WorkerServer::class, 'agentManager')->setValue($server, $manager);

        return $server;
    }
}

/**
 * Worker server that records the workers it is asked to launch instead of launching them.
 */
final class PoolGrowthTestWorkerServer extends WorkerServer
{
    /** @var list<bool> Monopolistic flag of every worker launch asked for, in order */
    public array $orderedWorkers = [];

    /**
     * @param bool $isMonopolistic True if monopolistic worker
     */
    protected function startWorker(bool $isMonopolistic): void
    {
        $this->orderedWorkers[] = $isMonopolistic;
    }

    /**
     * @param string $agentType Agent type to start
     * @param ?string $agentIndex Agent index to start
     * @throws AgentDaemonCreationFailedException If the agent daemon cannot be created
     * @throws NoSuitableWorkerException When no worker is free and the pool may not grow
     */
    public function startAgentPublic(string $agentType, ?string $agentIndex = null): void
    {
        $this->startAgent($agentType, $agentIndex);
    }

    public function advancePublic(): void
    {
        $this->advanceAgentsAwaitingWorker();
    }

    public function advanceRosterPublic(): void
    {
        $this->advanceProtectedModeRoster();
    }

    public function markPreparingShutdown(): void
    {
        $this->preparingShutdown = true;
    }

    /**
     * Registers a free monopolistic worker, the way a launched process does once it connects.
     *
     * @param int $workerIndex Index the worker registers with
     * @return PoolGrowthTestWorkerClient The registered worker
     */
    public function addWorker(int $workerIndex): PoolGrowthTestWorkerClient
    {
        $client = new ReflectionClass(PoolGrowthTestWorkerClient::class)->newInstanceWithoutConstructor();
        $client->setWorkerIndex($workerIndex);
        $client->setIsMonopolistic(true);
        $this->clients[] = $client;

        return $client;
    }

    /**
     * Moves every wait's deadline into the past, as if the start deadline had run out.
     */
    public function expireWaits(): void
    {
        $register = new ReflectionProperty(WorkerServer::class, 'agentsAwaitingWorker');
        $expired = [];
        foreach ($register->getValue($this) as $agentId => $awaiting) {
            $expired[$agentId] = new AwaitingWorkerAgent(
                $awaiting->agentId,
                $awaiting->agentType,
                $awaiting->agentIndex,
                $awaiting->placedByLeader,
                0.0,
            );
        }
        $register->setValue($this, $expired);
    }

    protected function onStart(): void
    {
    }
}

/**
 * Worker client that records the agent starts sent down it instead of writing to a socket.
 */
final class PoolGrowthTestWorkerClient extends WorkerClient
{
    /** @var list<string> Agent types a start was sent for, in order */
    public array $startedAgentTypes = [];

    /**
     * @param string $agentType Agent type to start
     * @param ?string $agentIndex Agent index to start
     * @param list<string> $liveAcceptKeys Accept keys of the node's live sockets; unused here
     */
    public function sendAgentStart(string $agentType, ?string $agentIndex = null, array $liveAcceptKeys = []): void
    {
        $this->startedAgentTypes[] = $agentType;
    }
}

/**
 * Sink that keeps the cards a server reports instead of handing them to a project.
 */
final class PoolGrowthTestFailureSink implements ContainedFailureSink
{
    /** @var list<ContainedFailure> Cards reported, in order */
    public array $reported = [];

    /**
     * @param ContainedFailure $failure Card the server reported
     */
    public function reportContainedFailure(ContainedFailure $failure): void
    {
        $this->reported[] = $failure;
    }
}

/**
 * Start sink that keeps the agents whose start was reported failed.
 */
final class PoolGrowthTestStartSink implements AgentStartSink
{
    /** @var list<string> Agent ids reported failed, in order */
    public array $failedAgentIds = [];

    /**
     * @param string $agentId Agent that started; unused here
     */
    public function onAgentStarted(string $agentId): void
    {
    }

    /**
     * @param string $agentId Agent whose start failed
     * @param string $reason Why; unused here
     */
    public function onAgentStartFailed(string $agentId, string $reason): void
    {
        $this->failedAgentIds[] = $agentId;
    }

    /**
     * @param string $agentId Agent that stopped; unused here
     */
    public function onAgentStopped(string $agentId): void
    {
    }
}

/**
 * Agent manager whose factory answers every type this test declares.
 */
final class PoolGrowthTestAgentManagerDaemon extends AgentManagerDaemon
{
    /**
     * @param string $agentType Agent type that was asked for
     * @param ?string $agentIndex Agent index that was asked for
     * @return AgentDaemonInterface Daemon that is monopolistic unless it is the regular type
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        return new PoolGrowthTestAgentDaemon($agentIndex, $agentType !== 'crowded');
    }
}

/**
 * Agent daemon that declares nothing but whether it needs a process of its own.
 */
final class PoolGrowthTestAgentDaemon extends AbstractAgentDaemon
{
    public function __construct(?string $agentIndex, private readonly bool $monopolistic)
    {
        $this->agentIndex = $agentIndex;
    }

    public function requiresMonopolisticProcess(): bool
    {
        return $this->monopolistic;
    }

    /**
     * @param AgentMessageDTOInterface $message Message that would go to a user; unused here
     */
    public function sendToUser(AgentMessageDTOInterface $message): void
    {
    }
}

/**
 * Project facade declaring the leader-hosted agents this test starts.
 *
 * Abstract because only its registry constant is read: the start never builds a database.
 */
abstract class PoolGrowthTestHilos extends Hilos
{
    public const array AGENTS = [
        'lonely' => [],
        'solitary' => [],
        'crowded' => [],
        'initiator' => [],
    ];
}
