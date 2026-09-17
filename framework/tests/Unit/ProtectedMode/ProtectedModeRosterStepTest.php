<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\ProtectedMode;

use Hilos\Cluster\ClusterContext;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\DTO\AgentMessageDTOInterface;
use Hilos\Hilos;
use Hilos\ProtectedMode\DTO\ProtectedModeCircleSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeDisableSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeEnableSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModePassSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeProgressSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeRefreezeSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeVerifySignalData;
use Hilos\ProtectedMode\ProtectedModeSwitch;
use Hilos\Socket\Server\WorkerServer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * Unit tests for the protected-mode roster walk taken one agent per master pass (HIL-1012).
 *
 * The freeze used to stop the whole roster inside one call from the master's loop, and the lift
 * used to bring it back the same way, so every client of the node stood behind a walk as long as
 * the roster. What is pinned here is the step: a request only queues, each pass moves exactly one
 * agent, and the switch hears the end of a walk once and only on the pass that empties it. Two
 * requests can overlap now that a walk takes time, and the case that could lose an agent for good
 * - a stop over an unfinished lift - is named by the agent it would lose.
 *
 * Built like the freeze gate's own test: the server skips its constructor, and a stop reaches no
 * worker, so the roster keeps its records and what the walk did is read off the remembered set.
 */
final class ProtectedModeRosterStepTest extends TestCase
{
    private const string INITIATOR_TYPE = 'restorer';

    /** @var list<string> Roster the node carries into the freeze */
    private const array ROSTER = ['chat', 'poll', 'todo', 'presence', 'search'];

    private RosterStepTestAgentManagerDaemon $manager;

    private RosterStepTestWorkerServer $server;

    private RosterStepTestSwitch $switch;

    protected function setUp(): void
    {
        $this->switch = new RosterStepTestSwitch();
        Hilos::$cluster = new ClusterContext();
        Hilos::$cluster->registerProtectedMode($this->switch);

        $this->manager = new RosterStepTestAgentManagerDaemon();
        // Skip the constructor: it reads worker env and creates a log directory that the walk
        // does not touch. Only the agent manager is needed.
        $this->server = new ReflectionClass(RosterStepTestWorkerServer::class)->newInstanceWithoutConstructor();
        new ReflectionProperty(WorkerServer::class, 'agentManager')->setValue($this->server, $this->manager);
        $this->server->switch = $this->switch;
    }

    protected function tearDown(): void
    {
        Hilos::$cluster = null;

        parent::tearDown();
    }

    public function testEachPassStopsExactlyOneAgent(): void
    {
        $this->seedRoster(self::ROSTER);

        $this->server->stopAgentsForProtectedMode(self::INITIATOR_TYPE, null);
        $this->assertSame([], $this->server->getProtectedModeStoppedAgents(), 'The request only queues.');

        $this->server->rosterStepPublic();
        $this->assertSame(['chat'], $this->server->getProtectedModeStoppedAgents());

        $this->steps(4);
        $this->assertSame(self::ROSTER, $this->server->getProtectedModeStoppedAgents());
    }

    public function testTheSwitchHearsTheStoppedRosterOnceAndOnlyOnTheLastPass(): void
    {
        $this->seedRoster(self::ROSTER);
        $this->server->stopAgentsForProtectedMode(self::INITIATOR_TYPE, null);

        $this->steps(4);
        $this->assertSame([], $this->switch->heard, 'Four agents of five stopped is not a frozen node.');

        $this->server->rosterStepPublic();
        $this->assertSame(['stopped'], $this->switch->heard);

        $this->steps(3);
        $this->assertSame(['stopped'], $this->switch->heard);
    }

    public function testTheLiftBringsOneAgentBackPerPassAndFinishesOnce(): void
    {
        $this->freezeWholeRoster();

        $this->server->resumeAgentsForProtectedMode();
        $this->assertSame([], $this->manager->askedFor, 'The request only queues.');

        $this->steps(4);
        $this->assertSame(['chat', 'poll', 'todo', 'presence'], $this->manager->askedFor);
        $this->assertSame([], $this->switch->heard);

        $this->server->rosterStepPublic();
        $this->assertSame(self::ROSTER, $this->manager->askedFor);
        // The application's own hook runs after the replay and before the switch finishes the lift.
        $this->assertSame(['lifted', 'resumed'], $this->switch->heard);

        $this->steps(3);
        $this->assertSame(['lifted', 'resumed'], $this->switch->heard);
    }

    public function testTheInitiatorAndTheMailPoolAreNeverStopped(): void
    {
        $this->seedRoster(['chat', self::INITIATOR_TYPE, HilosAgentType::HILOS_MAIL, 'poll']);

        $this->server->stopAgentsForProtectedMode(self::INITIATOR_TYPE, null);
        $this->steps(2);

        $this->assertSame(['chat', 'poll'], $this->server->getProtectedModeStoppedAgents());
        // Two passes close the walk: neither of the two left running costs a pass of its own.
        $this->assertSame(['stopped'], $this->switch->heard);
    }

    public function testAStopOverAnUnfinishedLiftKeepsTheAgentsTheLiftHadNotAskedFor(): void
    {
        $this->freezeWholeRoster();
        $this->server->resumeAgentsForProtectedMode();
        $this->steps(2);

        // Closing the verification window back re-freezes the node, and nothing holds it until the
        // lift is done. Only chat and poll were asked for; todo, presence and search are on no
        // roster yet, so the snapshot cannot see them.
        $this->manager->forgetRoster();
        $this->seedRoster(['chat', 'poll']);
        $this->server->stopAgentsForProtectedMode(self::INITIATOR_TYPE, null);
        $this->steps(2);

        $this->assertSame(
            ['todo', 'presence', 'search', 'chat', 'poll'],
            $this->server->getProtectedModeStoppedAgents(),
            'Without the carry-over the next lift would never ask for todo, presence or search again.',
        );
        $this->assertSame(['stopped'], $this->switch->heard, 'The overtaken lift is never finished.');
    }

    public function testALiftOverAnUnfinishedStopDropsWhatTheStopHadNotReached(): void
    {
        $this->seedRoster(self::ROSTER);
        $this->server->stopAgentsForProtectedMode(self::INITIATOR_TYPE, null);
        $this->steps(2);

        $this->manager->forgetRoster();
        $this->server->resumeAgentsForProtectedMode();
        $this->steps(5);

        // todo, presence and search were never stopped, so they are still running and nothing
        // about them was remembered.
        $this->assertSame(['chat', 'poll'], $this->manager->askedFor);
        $this->assertSame(['lifted', 'resumed'], $this->switch->heard, 'The overtaken stop is never finished.');
    }

    public function testALiftOverAnUnfinishedLiftKeepsWhatTheFirstHadNotAskedFor(): void
    {
        // The window opened and the mode lifted before the window's replay was done.
        $this->freezeWholeRoster();
        $this->server->resumeAgentsForProtectedMode();
        $this->steps(2);

        $this->server->resumeAgentsForProtectedMode();
        $this->steps(3);

        $this->assertSame(self::ROSTER, $this->manager->askedFor);
        $this->assertSame(['lifted', 'resumed'], $this->switch->heard);
    }

    public function testAWalkWithNothingToMoveStillFinishes(): void
    {
        // A node running nothing but the initiator is still frozen, and still owed a lift frame.
        $this->server->stopAgentsForProtectedMode(self::INITIATOR_TYPE, null);
        $this->server->rosterStepPublic();
        $this->assertSame(['stopped'], $this->switch->heard);

        $this->server->resumeAgentsForProtectedMode();
        $this->server->rosterStepPublic();
        $this->assertSame(['stopped', 'lifted', 'resumed'], $this->switch->heard);
    }

    /**
     * Stops the whole seeded roster and forgets it, leaving only the remembered set to lift.
     */
    private function freezeWholeRoster(): void
    {
        $this->seedRoster(self::ROSTER);
        $this->server->stopAgentsForProtectedMode(self::INITIATOR_TYPE, null);
        $this->steps(count(self::ROSTER));
        $this->manager->forgetRoster();
        $this->switch->heard = [];
    }

    /**
     * @param list<string> $agentTypes Singleton agent types to register on the node's roster
     */
    private function seedRoster(array $agentTypes): void
    {
        foreach ($agentTypes as $agentType) {
            $this->manager->registerUnlinked($agentType);
        }
    }

    /**
     * @param int $count Roster steps to take, one per master pass
     */
    private function steps(int $count): void
    {
        for ($step = 0; $step < $count; $step++) {
            $this->server->rosterStepPublic();
        }
    }
}

/**
 * Worker server that exposes the roster step and records its lift hook onto the switch's log, so
 * the order of the two is observable.
 */
final class RosterStepTestWorkerServer extends WorkerServer
{
    /** @var RosterStepTestSwitch Switch whose log the lift hook writes to */
    public RosterStepTestSwitch $switch;

    public function rosterStepPublic(): void
    {
        $this->advanceProtectedModeRoster();
    }

    protected function onProtectedModeLifted(): void
    {
        $this->switch->heard[] = 'lifted';
    }

    protected function onStart(): void
    {
        // Not used in this test
    }
}

/**
 * Agent manager whose factory answers for every type and remembers it was asked.
 */
final class RosterStepTestAgentManagerDaemon extends AgentManagerDaemon
{
    /** Worker index a record carries before a worker is picked for it */
    private const int UNLINKED_WORKER_INDEX = 0;

    /** @var list<string> Ids of the agents the factory was asked for after the roster was last forgotten */
    public array $askedFor = [];

    /**
     * @param string $agentType Singleton agent type to register unlinked from any worker
     */
    public function registerUnlinked(string $agentType): void
    {
        $this->createAndAddAgent($agentType, null, self::UNLINKED_WORKER_INDEX, false);
        $this->askedFor = [];
    }

    /**
     * Empties the roster, standing in for the stops a live worker link completes, and forgets
     * what the factory was asked so far.
     */
    public function forgetRoster(): void
    {
        foreach (array_keys($this->getAgents()) as $agentId) {
            $this->removeAgent($agentId);
        }
        $this->askedFor = [];
    }

    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        $this->askedFor[] = $agentType;

        return new RosterStepTestAgentDaemon($agentIndex);
    }
}

/**
 * Agent daemon that requires nothing, so only the walk decides its fate.
 */
final class RosterStepTestAgentDaemon extends AbstractAgentDaemon
{
    public function __construct(?string $agentIndex)
    {
        $this->agentIndex = $agentIndex;
    }

    public function requiresMonopolisticProcess(): bool
    {
        return false;
    }

    public function sendToUser(AgentMessageDTOInterface $message): void
    {
        // Not used in this test
    }
}

/**
 * Switch fake that records the two walk endings and nothing else.
 */
final class RosterStepTestSwitch implements ProtectedModeSwitch
{
    /** @var list<string> What was heard, in order: stopped, resumed, and the lift hook's lifted */
    public array $heard = [];

    public function requestEnable(ProtectedModeEnableSignalData $data): void
    {
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

    public function requestCircle(ProtectedModeCircleSignalData $data): void
    {
    }

    public function requestRefreeze(ProtectedModeRefreezeSignalData $data): void
    {
    }

    public function onRosterStopped(): void
    {
        $this->heard[] = 'stopped';
    }

    public function onRosterResumed(): void
    {
        $this->heard[] = 'resumed';
    }
}
