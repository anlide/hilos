<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Leadership;
use Hilos\Constants\AgentConstants;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentRegistryKey;
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
use Hilos\Core\Process;
use Hilos\Hilos;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Server\AwaitingWorkerAgent;
use Hilos\Socket\Server\WorkerServer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * The monopolistic worker pool grows on demand instead of refusing the agent it ran out for (HIL-998),
 * and a regular agent waits in the same queue for a regular worker already launched (HIL-1671).
 *
 * A monopolistic agent that finds no free worker orders one and waits for it; a regular agent that
 * finds none while a regular worker this master launched has not connected yet waits for that one
 * and orders nothing. The next master pass that finds a free worker of the agent's type seats it,
 * and a wait that outlives its ten seconds ends as the refusal a shortage used to be at once.
 * Everything else that found no worker - a regular agent with no regular worker launched, a node on
 * its way out, a per-instance monopolistic agent - is refused exactly as before.
 */
final class WorkerServerPoolGrowthTest extends TestCase
{
    private const string MONOPOLISTIC_TYPE = 'lonely';

    private const string SECOND_MONOPOLISTIC_TYPE = 'solitary';

    private const string REGULAR_TYPE = 'crowded';

    private const string PLACED_REGULAR_TYPE = 'placed';

    private const string INITIATOR_TYPE = 'initiator';

    private const string AGENT_INDEX = '1';

    private const int FIRST_WORKER_INDEX = 17;

    private const int SECOND_WORKER_INDEX = 18;

    private const int REGULAR_WORKER_INDEX = 19;

    private const int FRAME_COUNT = 10;

    /** Mirrors WorkerServer's private limit on the wait for a worker to connect. */
    private const float WORKER_CONNECT_WAIT_SECONDS = 10.0;

    /** A moment well inside that wait and past the start deadline the wait used to have. */
    private const float SEVENTH_SECOND = 7.0;

    /** @var class-string<Hilos> App class bound before this test touched it */
    private string $boundAppClass;

    /** Cluster context bound before this test touched it. */
    private ?ClusterContext $boundCluster;

    protected function setUp(): void
    {
        $this->boundAppClass = Hilos::appClass();
        $this->boundCluster = Hilos::$cluster;
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, PoolGrowthTestHilos::class);
        Hilos::$cluster = null;
        Hilos::$rt = null;
    }

    protected function tearDown(): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->boundAppClass);
        Hilos::$cluster = $this->boundCluster;

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
     * The leaf's own case (HIL-1671): a regular worker is launched and has not connected yet, so the
     * regular agent waits for it in the same queue - no worker is ordered and nothing is refused.
     */
    public function testARegularAgentWaitsForALaunchedRegularWorkerWithoutOrderingOne(): void
    {
        $manager = new PoolGrowthTestAgentManagerDaemon();
        $startSink = new PoolGrowthTestStartSink();
        $manager->registerAgentStartSink($startSink);
        $server = $this->buildServer($manager);
        $failureSink = new PoolGrowthTestFailureSink();
        $server->setContainedFailureSink($failureSink);
        $server->launchRegularWorker(self::REGULAR_WORKER_INDEX);

        $askedAt = microtime(true);
        $server->startAgentPublic(self::REGULAR_TYPE);
        $answeredAt = microtime(true);

        $this->assertSame([], $server->orderedWorkers);
        $this->assertTrue($server->isAgentAwaitingWorker(self::REGULAR_TYPE));
        $this->assertTrue($manager->hasAgent(self::REGULAR_TYPE));
        $this->assertSame([self::REGULAR_TYPE], $server->agentsStillStarting());
        $this->assertSame([], $startSink->failedAgentIds);
        $this->assertSame([], $failureSink->reported);
        $awaiting = $server->awaitingWorker()[self::REGULAR_TYPE];
        $this->assertFalse($awaiting->requiresMonopolistic);
        $this->assertFalse($awaiting->placedByLeader);
        $this->assertNull($awaiting->agentIndex);
        $this->assertGreaterThanOrEqual($askedAt + self::WORKER_CONNECT_WAIT_SECONDS, $awaiting->deadline);
        $this->assertLessThanOrEqual($answeredAt + self::WORKER_CONNECT_WAIT_SECONDS, $awaiting->deadline);
    }

    /**
     * One regular worker takes every regular agent waiting, an indexed one too, and a monopolistic
     * worker that connects first takes none of them.
     */
    public function testARegularWorkerSeatsEveryWaitingRegularAgentAndAMonopolisticOneSeatsNone(): void
    {
        $manager = new PoolGrowthTestAgentManagerDaemon();
        $startSink = new PoolGrowthTestStartSink();
        $manager->registerAgentStartSink($startSink);
        $server = $this->buildServer($manager);
        $server->launchRegularWorker(self::REGULAR_WORKER_INDEX);
        $server->startAgentPublic(self::REGULAR_TYPE);
        $server->startAgentPublic(self::REGULAR_TYPE, self::AGENT_INDEX);
        $indexedAgentId = self::REGULAR_TYPE . AgentConstants::ID_SEPARATOR . self::AGENT_INDEX;
        $this->assertSame(self::AGENT_INDEX, $server->awaitingWorker()[$indexedAgentId]->agentIndex);

        $monopolistic = $server->addWorker(self::FIRST_WORKER_INDEX);
        $server->advancePublic();

        $this->assertSame([], $monopolistic->startedAgentTypes);
        $this->assertTrue($server->isAgentAwaitingWorker(self::REGULAR_TYPE));
        $this->assertTrue($server->isAgentAwaitingWorker($indexedAgentId));

        $regular = $server->addRegularWorker(self::REGULAR_WORKER_INDEX);
        $server->advancePublic();

        $this->assertSame([self::REGULAR_TYPE, self::REGULAR_TYPE], $regular->startedAgentTypes);
        $this->assertSame([null, self::AGENT_INDEX], $regular->startedAgentIndexes);
        $this->assertSame([], $monopolistic->startedAgentTypes);
        $this->assertSame(self::REGULAR_WORKER_INDEX, $manager->getAgentWorkerId(self::REGULAR_TYPE));
        $this->assertSame(self::REGULAR_WORKER_INDEX, $manager->getAgentWorkerId($indexedAgentId));
        $this->assertSame([], $server->awaitingWorker());
        $this->assertSame([], $server->orderedWorkers);
        $this->assertSame([], $startSink->failedAgentIds);
    }

    /**
     * The other way round: a regular worker that connects is not handed a monopolistic agent waiting
     * for the worker raised for it.
     */
    public function testARegularWorkerDoesNotSeatAWaitingMonopolisticAgent(): void
    {
        $manager = new PoolGrowthTestAgentManagerDaemon();
        $server = $this->buildServer($manager);
        $server->startAgentPublic(self::MONOPOLISTIC_TYPE);

        $regular = $server->addRegularWorker(self::REGULAR_WORKER_INDEX);
        $server->advancePublic();

        $this->assertSame([], $regular->startedAgentTypes);
        $this->assertTrue($server->isAgentAwaitingWorker(self::MONOPOLISTIC_TYPE));
        $this->assertTrue($server->awaitingWorker()[self::MONOPOLISTIC_TYPE]->requiresMonopolistic);

        $monopolistic = $server->addWorker(self::FIRST_WORKER_INDEX);
        $server->advancePublic();

        $this->assertSame([self::MONOPOLISTIC_TYPE], $monopolistic->startedAgentTypes);
        $this->assertSame(-self::FIRST_WORKER_INDEX, $manager->getAgentWorkerId(self::MONOPOLISTIC_TYPE));
    }

    /**
     * A start that carried the placement sanction is seated with it: on a node that is not the
     * leader, a policy-placed agent is refused without it.
     */
    public function testARegularPlacementIsSeatedWithItsPlacementSanction(): void
    {
        $cluster = new ClusterContext();
        $cluster->registerLeadership(new PoolGrowthTestFollowerLeadership());
        Hilos::$cluster = $cluster;
        $manager = new PoolGrowthTestAgentManagerDaemon();
        $startSink = new PoolGrowthTestStartSink();
        $manager->registerAgentStartSink($startSink);
        $server = $this->buildServer($manager);
        $server->launchRegularWorker(self::REGULAR_WORKER_INDEX);

        $this->assertNull($server->executePlacement(self::PLACED_REGULAR_TYPE, null));
        $this->assertTrue($server->awaitingWorker()[self::PLACED_REGULAR_TYPE]->placedByLeader);

        $regular = $server->addRegularWorker(self::REGULAR_WORKER_INDEX);
        $server->advancePublic();

        $this->assertSame([self::PLACED_REGULAR_TYPE], $regular->startedAgentTypes);
        $this->assertSame(self::REGULAR_WORKER_INDEX, $server->placedWorkerId(self::PLACED_REGULAR_TYPE, null));
        $this->assertSame([], $startSink->failedAgentIds);
    }

    public function testManyFramesForOneWaitingRegularAgentLeaveItsWaitAsItWas(): void
    {
        $server = $this->buildServer(new PoolGrowthTestAgentManagerDaemon());
        $server->launchRegularWorker(self::REGULAR_WORKER_INDEX);
        $server->startAgentPublic(self::REGULAR_TYPE);
        $register = $server->awaitingWorker();

        for ($frame = 0; $frame < self::FRAME_COUNT; $frame++) {
            $server->startAgentPublic(self::REGULAR_TYPE);
        }

        // The very same record: no second wait, and no later deadline
        $this->assertSame($register, $server->awaitingWorker());
        $this->assertSame([], $server->orderedWorkers);
    }

    /**
     * The wait is ten seconds for both types: at the seventh - past the five the monopolistic wait
     * used to have - both still wait, and both are seated when their workers connect.
     */
    public function testBothTypesStillWaitAtTheSeventhSecondAndAreSeatedThen(): void
    {
        $manager = new PoolGrowthTestAgentManagerDaemon();
        $startSink = new PoolGrowthTestStartSink();
        $manager->registerAgentStartSink($startSink);
        $server = $this->buildServer($manager);
        $failureSink = new PoolGrowthTestFailureSink();
        $server->setContainedFailureSink($failureSink);
        $server->launchRegularWorker(self::REGULAR_WORKER_INDEX);
        $server->startAgentPublic(self::MONOPOLISTIC_TYPE);
        $server->startAgentPublic(self::REGULAR_TYPE);

        $server->ageWaits(self::SEVENTH_SECOND);
        $server->advancePublic();

        $this->assertTrue($server->isAgentAwaitingWorker(self::MONOPOLISTIC_TYPE));
        $this->assertTrue($server->isAgentAwaitingWorker(self::REGULAR_TYPE));
        $this->assertSame([], $failureSink->reported);

        $monopolistic = $server->addWorker(self::FIRST_WORKER_INDEX);
        $regular = $server->addRegularWorker(self::REGULAR_WORKER_INDEX);
        $server->advancePublic();

        $this->assertSame([self::MONOPOLISTIC_TYPE], $monopolistic->startedAgentTypes);
        $this->assertSame([self::REGULAR_TYPE], $regular->startedAgentTypes);
        $this->assertSame([], $server->awaitingWorker());
        $this->assertSame([], $failureSink->reported);
        $this->assertSame([], $startSink->failedAgentIds);
    }

    /**
     * At ten seconds each wait ends once, as the refusal of its own worker type, and leaves nothing
     * behind for a later worker or a later pass.
     */
    public function testAtTenSecondsEachWaitIsRefusedOnceAsItsOwnWorkerType(): void
    {
        $manager = new PoolGrowthTestAgentManagerDaemon();
        $startSink = new PoolGrowthTestStartSink();
        $manager->registerAgentStartSink($startSink);
        $server = $this->buildServer($manager);
        $failureSink = new PoolGrowthTestFailureSink();
        $server->setContainedFailureSink($failureSink);
        $server->launchRegularWorker(self::REGULAR_WORKER_INDEX);
        $server->startAgentPublic(self::MONOPOLISTIC_TYPE);
        $server->startAgentPublic(self::REGULAR_TYPE);

        $server->ageWaits(self::WORKER_CONNECT_WAIT_SECONDS);
        $server->advancePublic();

        $this->assertSame([self::MONOPOLISTIC_TYPE, self::REGULAR_TYPE], $startSink->failedAgentIds);
        $this->assertCount(2, $failureSink->reported);
        $this->assertSame(self::MONOPOLISTIC_TYPE, $failureSink->reported[0]->address);
        $this->assertInstanceOf(NoSuitableWorkerException::class, $failureSink->reported[0]->failure);
        $this->assertSame(
            'No suitable ' . WorkerConstants::TYPE_MONOPOLISTIC . ' worker available. Required: monopolistic worker without agents.',
            $failureSink->reported[0]->failure->getMessage(),
        );
        $this->assertSame(self::REGULAR_TYPE, $failureSink->reported[1]->address);
        $this->assertInstanceOf(NoSuitableWorkerException::class, $failureSink->reported[1]->failure);
        $this->assertSame(
            'No suitable ' . WorkerConstants::TYPE_REGULAR . ' worker available. Required: regular worker.',
            $failureSink->reported[1]->failure->getMessage(),
        );
        $this->assertFalse($manager->hasAgent(self::MONOPOLISTIC_TYPE));
        $this->assertFalse($manager->hasAgent(self::REGULAR_TYPE));
        $this->assertSame([], $server->awaitingWorker());
        $this->assertSame([], $server->agentsStillStarting());

        $regular = $server->addRegularWorker(self::REGULAR_WORKER_INDEX);
        $server->advancePublic();
        $this->assertSame([], $regular->startedAgentTypes);
        $this->assertCount(2, $failureSink->reported);
        $this->assertSame([self::MONOPOLISTIC_TYPE, self::REGULAR_TYPE], $startSink->failedAgentIds);
    }

    /**
     * A regular worker that dies before it registers is not ordered again by the wait: the agent
     * waits out its ten seconds and is refused, and the regular pool's own upkeep replaces the dead.
     */
    public function testARegularWorkerDyingBeforeItRegistersOrdersNothingAndTheWaitRunsOut(): void
    {
        $manager = new PoolGrowthTestAgentManagerDaemon();
        $startSink = new PoolGrowthTestStartSink();
        $manager->registerAgentStartSink($startSink);
        $server = $this->buildServer($manager);
        $server->launchRegularWorker(self::REGULAR_WORKER_INDEX);
        $server->startAgentPublic(self::REGULAR_TYPE);

        $server->loseRegularWorker(self::REGULAR_WORKER_INDEX);
        $server->advancePublic();

        $this->assertTrue($server->isAgentAwaitingWorker(self::REGULAR_TYPE));
        $this->assertSame([], $server->orderedWorkers);

        $server->ageWaits(self::WORKER_CONNECT_WAIT_SECONDS);
        $server->advancePublic();

        $this->assertFalse($server->isAgentAwaitingWorker(self::REGULAR_TYPE));
        $this->assertFalse($manager->hasAgent(self::REGULAR_TYPE));
        $this->assertSame([self::REGULAR_TYPE], $startSink->failedAgentIds);
        $this->assertSame([], $server->orderedWorkers);
    }

    public function testANodeOnItsWayOutDoesNotWaitForALaunchedRegularWorker(): void
    {
        $manager = new PoolGrowthTestAgentManagerDaemon();
        $server = $this->buildServer($manager);
        $server->launchRegularWorker(self::REGULAR_WORKER_INDEX);
        $server->markPreparingShutdown();

        $this->assertRefused($server, self::REGULAR_TYPE, null);
        $this->assertFalse($manager->hasAgent(self::REGULAR_TYPE));
    }

    /**
     * A stop takes a waiting regular agent out of the wait the way it takes a monopolistic one: one
     * word to the frames held for it, and nothing for the worker that connects later.
     */
    public function testARevokeTakesAWaitingRegularAgentOutOfTheWait(): void
    {
        $manager = new PoolGrowthTestAgentManagerDaemon();
        $startSink = new PoolGrowthTestStartSink();
        $manager->registerAgentStartSink($startSink);
        $server = $this->buildServer($manager);
        $server->launchRegularWorker(self::REGULAR_WORKER_INDEX);
        $this->assertNull($server->executePlacement(self::REGULAR_TYPE, null));

        $server->revokePlacement(self::REGULAR_TYPE, null);

        $this->assertFalse($server->isAgentAwaitingWorker(self::REGULAR_TYPE));
        $this->assertFalse($manager->hasAgent(self::REGULAR_TYPE));
        $this->assertSame([self::REGULAR_TYPE], $startSink->failedAgentIds);
        $regular = $server->addRegularWorker(self::REGULAR_WORKER_INDEX);
        $server->advancePublic();
        $this->assertSame([], $regular->startedAgentTypes);
        $this->assertSame([self::REGULAR_TYPE], $startSink->failedAgentIds);
    }

    public function testAFreezeTakesAWaitingRegularAgentOutOfTheWait(): void
    {
        $manager = new PoolGrowthTestAgentManagerDaemon();
        $startSink = new PoolGrowthTestStartSink();
        $manager->registerAgentStartSink($startSink);
        $server = $this->buildServer($manager);
        $server->launchRegularWorker(self::REGULAR_WORKER_INDEX);
        $server->startAgentPublic(self::REGULAR_TYPE);

        $server->stopAgentsForProtectedMode(self::INITIATOR_TYPE, null);
        $server->advanceRosterPublic();

        $this->assertFalse($server->isAgentAwaitingWorker(self::REGULAR_TYPE));
        $this->assertFalse($manager->hasAgent(self::REGULAR_TYPE));
        $this->assertSame([], $server->getProtectedModeStoppedAgents());
        $this->assertSame([self::REGULAR_TYPE], $startSink->failedAgentIds);
        $regular = $server->addRegularWorker(self::REGULAR_WORKER_INDEX);
        $server->advancePublic();
        $this->assertSame([], $regular->startedAgentTypes);
    }

    /**
     * A gate that refuses the start once the regular worker has connected ends the wait as a failed
     * start, rather than leaving the frames held for it waiting on nobody.
     */
    public function testAGateRefusingTheSeatOfARegularAgentEndsItsWaitAsAFailedStart(): void
    {
        $manager = new PoolGrowthTestAgentManagerDaemon();
        $startSink = new PoolGrowthTestStartSink();
        $manager->registerAgentStartSink($startSink);
        $server = $this->buildServer($manager);
        $server->launchRegularWorker(self::REGULAR_WORKER_INDEX);
        $server->startAgentPublic(self::REGULAR_TYPE);
        $server->refuseRtClaim(self::REGULAR_TYPE, null);

        $regular = $server->addRegularWorker(self::REGULAR_WORKER_INDEX);
        $server->advancePublic();

        $this->assertSame([], $regular->startedAgentTypes);
        $this->assertFalse($server->isAgentAwaitingWorker(self::REGULAR_TYPE));
        $this->assertFalse($manager->hasAgent(self::REGULAR_TYPE));
        $this->assertSame([self::REGULAR_TYPE], $startSink->failedAgentIds);
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
        return $this->registerWorker($workerIndex, true);
    }

    /**
     * Registers a regular worker, the way a launched regular process does once it connects.
     *
     * @param int $workerIndex Index the worker registers with
     * @return PoolGrowthTestWorkerClient The registered worker
     */
    public function addRegularWorker(int $workerIndex): PoolGrowthTestWorkerClient
    {
        return $this->registerWorker($workerIndex, false);
    }

    /**
     * Puts a running regular process on the roster, the way a launch does before the process connects.
     *
     * @param int $workerIndex Index the process was launched with
     */
    public function launchRegularWorker(int $workerIndex): void
    {
        $workers = new ReflectionProperty(WorkerServer::class, 'workers');
        $roster = $workers->getValue($this);
        $roster[WorkerConstants::TYPE_REGULAR . WorkerConstants::KEY_SEPARATOR . $workerIndex] = [
            WorkerConstants::FIELD_WORKER_PROCESS => new PoolGrowthTestProcess(),
            WorkerConstants::FIELD_WORKER_TYPE => WorkerConstants::TYPE_REGULAR,
            WorkerConstants::FIELD_WORKER_INDEX => $workerIndex,
        ];
        $workers->setValue($this, $roster);
    }

    /**
     * Takes a regular process off the roster, the way the process tick does once it has died.
     *
     * @param int $workerIndex Index of the dead process
     */
    public function loseRegularWorker(int $workerIndex): void
    {
        new ReflectionMethod(WorkerServer::class, 'removeWorker')->invoke(
            $this,
            WorkerConstants::TYPE_REGULAR . WorkerConstants::KEY_SEPARATOR . $workerIndex,
            WorkerConstants::TYPE_REGULAR,
            $workerIndex,
        );
    }

    /**
     * @return array<string, AwaitingWorkerAgent> Agents waiting for a worker, keyed by agent id
     */
    public function awaitingWorker(): array
    {
        return new ReflectionProperty(WorkerServer::class, 'agentsAwaitingWorker')->getValue($this);
    }

    /**
     * Moves every wait's deadline into the past, as if the start deadline had run out.
     */
    public function expireWaits(): void
    {
        $this->rewriteDeadlines(static fn (float $deadline): float => 0.0);
    }

    /**
     * Moves every wait's deadline earlier, as if that many seconds of it had passed.
     *
     * @param float $seconds Seconds of the wait to count as gone
     */
    public function ageWaits(float $seconds): void
    {
        $this->rewriteDeadlines(static fn (float $deadline): float => $deadline - $seconds);
    }

    /**
     * @param callable(float): float $deadlineOf New deadline of a wait, from its current one
     */
    private function rewriteDeadlines(callable $deadlineOf): void
    {
        $register = new ReflectionProperty(WorkerServer::class, 'agentsAwaitingWorker');
        $rewritten = [];
        foreach ($register->getValue($this) as $agentId => $awaiting) {
            $rewritten[$agentId] = new AwaitingWorkerAgent(
                $awaiting->agentId,
                $awaiting->agentType,
                $awaiting->agentIndex,
                $awaiting->placedByLeader,
                $deadlineOf($awaiting->deadline),
                $awaiting->requiresMonopolistic,
            );
        }
        $register->setValue($this, $rewritten);
    }

    /**
     * @param int $workerIndex Index the worker registers with
     * @param bool $isMonopolistic Whether the worker registers as monopolistic
     * @return PoolGrowthTestWorkerClient The registered worker
     */
    private function registerWorker(int $workerIndex, bool $isMonopolistic): PoolGrowthTestWorkerClient
    {
        $client = new ReflectionClass(PoolGrowthTestWorkerClient::class)->newInstanceWithoutConstructor();
        $client->setWorkerIndex($workerIndex);
        $client->setIsMonopolistic($isMonopolistic);
        new ReflectionProperty(WorkerClient::class, 'isRegistered')->setValue($client, true);
        $this->clients[] = $client;

        return $client;
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

    /** @var list<?string> Agent indexes a start was sent for, in the same order */
    public array $startedAgentIndexes = [];

    /**
     * @param string $agentType Agent type to start
     * @param ?string $agentIndex Agent index to start
     * @param list<string> $liveAcceptKeys Accept keys of the node's live sockets; unused here
     */
    public function sendAgentStart(string $agentType, ?string $agentIndex = null, array $liveAcceptKeys = []): void
    {
        $this->startedAgentTypes[] = $agentType;
        $this->startedAgentIndexes[] = $agentIndex;
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
    /** @var list<string> Agent types that run on a regular worker; every other type is monopolistic */
    private const array REGULAR_TYPES = ['crowded', 'placed'];

    /**
     * @param string $agentType Agent type that was asked for
     * @param ?string $agentIndex Agent index that was asked for
     * @return AgentDaemonInterface Daemon that is monopolistic unless it is a regular type
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        return new PoolGrowthTestAgentDaemon($agentIndex, !in_array($agentType, self::REGULAR_TYPES, true));
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
 * Process on the roster that is running and has not connected, with no child behind it.
 */
final class PoolGrowthTestProcess extends Process
{
    public function __construct()
    {
    }

    /** @return array<string, mixed> Status of a running process */
    public function getStatus(): array
    {
        return [self::STATUS_RUNNING => true];
    }
}

/**
 * Leadership of a node that follows another leader.
 */
final class PoolGrowthTestFollowerLeadership implements Leadership
{
    public function amLeader(): bool
    {
        return false;
    }

    public function leaderId(): ?string
    {
        return 'leader';
    }

    public function leaderWebSocketOpen(): bool
    {
        return true;
    }

    public function hasQuorum(): bool
    {
        return true;
    }
}

/**
 * Project facade declaring the agents this test starts: leader-hosted, and one placed by policy.
 *
 * Abstract because only its registry constant is read: the start never builds a database.
 */
abstract class PoolGrowthTestHilos extends Hilos
{
    public const array AGENTS = [
        'lonely' => [],
        'solitary' => [],
        'crowded' => [],
        'placed' => [AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY],
        'initiator' => [],
    ];
}
