<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\DTO\AgentMessageDTOInterface;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Hilos;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Throwable;

/**
 * An agent that went down with its worker leaves the master's roster too.
 *
 * Two rules of the agent lifecycle are one bargain: an agent lives until its worker does, and
 * addressing an agent that is not there starts it. A worker that dies without reporting breaks
 * the second half through the first - no stop report arrives, so the roster goes on naming its
 * agents and the master takes them for alive, declining the very start that would bring one
 * back. The agent nothing ever stops on purpose is the one this strands for good: the mail pool
 * the freeze deliberately spares is never reported stopped, so its record is never cleared, and
 * the node sends no mail until the daemon restarts.
 */
final class WorkerDeathForgetsItsAgentsTest extends TestCase
{
    private const string AGENT_TYPE = 'worker_death_agent';

    /** Index of the worker these tests kill. */
    private const int HOST_WORKER = 3;

    /** @var class-string<Hilos> App class bound before this test touched it */
    private string $boundAppClass;

    protected function setUp(): void
    {
        $this->boundAppClass = Hilos::appClass();
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, WorkerDeathTestHilos::class);
    }

    protected function tearDown(): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->boundAppClass);

        parent::tearDown();
    }

    public function testAgentsOfADeadWorkerLeaveTheRoster(): void
    {
        $manager = new WorkerDeathTestAgentManagerDaemon();
        $manager->addAgent($this->agentId('1'), new WorkerDeathTestAgentDaemon('1'), self::HOST_WORKER, false);
        $manager->addAgent($this->agentId('2'), new WorkerDeathTestAgentDaemon('2'), self::HOST_WORKER, false);

        $lost = $manager->forgetAgentsOfWorker(self::HOST_WORKER, false);

        $this->assertSame([$this->agentId('1'), $this->agentId('2')], $lost);
        $this->assertFalse($manager->hasAgent($this->agentId('1')));
        $this->assertFalse($manager->hasAgent($this->agentId('2')));
    }

    public function testAnAgentOnAnotherWorkerIsUntouched(): void
    {
        $manager = new WorkerDeathTestAgentManagerDaemon();
        $manager->addAgent($this->agentId('1'), new WorkerDeathTestAgentDaemon('1'), self::HOST_WORKER, false);
        $manager->addAgent($this->agentId('2'), new WorkerDeathTestAgentDaemon('2'), self::HOST_WORKER + 1, false);

        $lost = $manager->forgetAgentsOfWorker(self::HOST_WORKER, false);

        $this->assertSame([$this->agentId('1')], $lost);
        $this->assertTrue($manager->hasAgent($this->agentId('2')));
    }

    public function testARegularAndAMonopolisticWorkerOfTheSameIndexAreToldApart(): void
    {
        // The roster keys a host by a signed id - monopolistic workers count down from zero - so
        // the two hosts numbered three are different hosts, and killing one must not touch the
        // other.
        $manager = new WorkerDeathTestAgentManagerDaemon();
        $manager->addAgent($this->agentId('1'), new WorkerDeathTestAgentDaemon('1'), self::HOST_WORKER, false);
        $manager->addAgent($this->agentId('2'), new WorkerDeathTestAgentDaemon('2'), self::HOST_WORKER, true);

        $lost = $manager->forgetAgentsOfWorker(self::HOST_WORKER, false);

        $this->assertSame([$this->agentId('1')], $lost);
        $this->assertTrue($manager->hasAgent($this->agentId('2')));
    }

    public function testTheDaemonSideStopHookRunsForEachLostAgent(): void
    {
        $manager = new WorkerDeathTestAgentManagerDaemon();
        $first = new WorkerDeathTestAgentDaemon('1');
        $second = new WorkerDeathTestAgentDaemon('2');
        $manager->addAgent($this->agentId('1'), $first, self::HOST_WORKER, false);
        $manager->addAgent($this->agentId('2'), $second, self::HOST_WORKER, false);

        $manager->forgetAgentsOfWorker(self::HOST_WORKER, false);

        $this->assertTrue($first->stopped);
        $this->assertTrue($second->stopped);
    }

    public function testAFailingStopHookDoesNotStrandTheRestOfTheRoster(): void
    {
        $manager = new WorkerDeathTestAgentManagerDaemon();
        $survivor = new WorkerDeathTestAgentDaemon('2');
        $manager->addAgent($this->agentId('1'), new WorkerDeathTestThrowingAgentDaemon('1'), self::HOST_WORKER, false);
        $manager->addAgent($this->agentId('2'), $survivor, self::HOST_WORKER, false);

        $lost = $manager->forgetAgentsOfWorker(self::HOST_WORKER, false);

        $this->assertSame([$this->agentId('1'), $this->agentId('2')], $lost);
        $this->assertFalse($manager->hasAgent($this->agentId('1')));
        $this->assertFalse($manager->hasAgent($this->agentId('2')));
        $this->assertTrue($survivor->stopped, 'The agent after the failing hook still has to be stopped.');
    }

    public function testARosterThatStillNamesTheAgentRefusesToStartItAgain(): void
    {
        // The defect itself, held so the assertion below means something: while the record
        // stands and claims a worker client, the frame that would revive the agent finds it
        // already there and starts nothing.
        $manager = new WorkerDeathTestAgentManagerDaemon();
        $server = $this->buildServer($manager);

        $this->sendFrame($server);
        $this->sendFrame($server);

        $this->assertSame([$this->agentId('1')], $server->startedAgentIds);
    }

    public function testAnAgentLostWithItsWorkerIsStartedAgainByTheNextFrame(): void
    {
        $manager = new WorkerDeathTestAgentManagerDaemon();
        $server = $this->buildServer($manager);

        $this->sendFrame($server);
        $manager->forgetAgentsOfWorker(self::HOST_WORKER, false);
        $this->sendFrame($server);

        $this->assertSame(
            [$this->agentId('1'), $this->agentId('1')],
            $server->startedAgentIds,
            'Once the roster forgets the dead worker, addressing the agent has to start it again.',
        );
    }

    /**
     * @param string $index Agent index
     * @return string Composed agent id
     */
    private function agentId(string $index): string
    {
        return self::AGENT_TYPE . ':' . $index;
    }

    /**
     * Delivers one frame addressed to the instance agent.
     *
     * @param WorkerDeathTestWorkerServer $server Server under test
     */
    private function sendFrame(WorkerDeathTestWorkerServer $server): void
    {
        try {
            $server->sendSignalToAgent(self::AGENT_TYPE, '1', new DaemonAgentMessageDTO(
                $this->agentId('1'),
                new SignalDTO(
                    new SignalSource(SignalSource::DAEMON),
                    new SignalType('noop'),
                    new SignalName('noop'),
                    new SignalData([]),
                ),
            ));
        } catch (Throwable) {
            // Expected: what this test reads is whether the frame reached a start, and no worker
            // process is registered here for the frame itself to travel down afterwards.
        }
    }

    /**
     * @param WorkerDeathTestAgentManagerDaemon $manager Agent manager the server routes starts through
     * @return WorkerDeathTestWorkerServer Server holding that manager
     */
    private function buildServer(WorkerDeathTestAgentManagerDaemon $manager): WorkerDeathTestWorkerServer
    {
        // Skip the constructor: it reads worker env and creates a log directory this test does
        // not exercise. Only the agent manager is needed.
        $server = new ReflectionClass(WorkerDeathTestWorkerServer::class)->newInstanceWithoutConstructor();

        new ReflectionProperty(WorkerServer::class, 'agentManager')->setValue($server, $manager);
        $server->managerDouble = $manager;

        return $server;
    }
}

/**
 * Worker server that records every start and stops short of picking a worker.
 *
 * Starts land on {@see WorkerDeathForgetsItsAgentsTest::HOST_WORKER} so the test can kill the
 * host the agent was placed on.
 */
final class WorkerDeathTestWorkerServer extends WorkerServer
{
    /** @var list<string> Agent ids this server was asked to start, in order */
    public array $startedAgentIds = [];

    /** The same manager the base class holds privately, so the start below can reach it. */
    public WorkerDeathTestAgentManagerDaemon $managerDouble;

    protected function startAgent(string $agentType, ?string $agentIndex = null): void
    {
        $agentId = $this->buildAgentId($agentType, $agentIndex);
        $this->startedAgentIds[] = $agentId;
        $this->managerDouble->addAgent(
            $agentId,
            $this->managerDouble->instantiateAgentDaemon($agentType, $agentIndex),
            3,
            false,
        );
    }

    protected function onStart(): void
    {
        // Not used in this test
    }
}

/**
 * Agent manager whose factory answers the one type this test declares.
 */
final class WorkerDeathTestAgentManagerDaemon extends AgentManagerDaemon
{
    /**
     * @param string $agentType Agent type that was asked for
     * @param ?string $agentIndex Agent index that was asked for
     * @return AgentDaemonInterface Daemon that reports itself linked, the way a live one does
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        return new WorkerDeathTestBoundAgentDaemon($agentIndex);
    }
}

/**
 * Agent daemon that records the stop it was given.
 */
class WorkerDeathTestAgentDaemon extends TopologyTestAgentDaemon
{
    /** True once the daemon-side stop hook has run. */
    public bool $stopped = false;

    public function __construct(?string $agentIndex)
    {
        $this->agentIndex = $agentIndex;
    }

    public function onStop(): void
    {
        $this->stopped = true;
    }

    /**
     * @param AgentMessageDTOInterface $message Message that would go to a user; unused here
     */
    public function sendToUser(AgentMessageDTOInterface $message): void
    {
        // Not used in this test
    }
}

/**
 * Agent daemon that answers a frame the way a linked one does.
 *
 * Standing in for the state the defect leaves behind: the record names a worker client, so the
 * master reads the agent as live and routes the frame instead of starting it.
 */
final class WorkerDeathTestBoundAgentDaemon extends WorkerDeathTestAgentDaemon
{
    public function hasWorkerClient(): bool
    {
        return true;
    }
}

/**
 * Agent daemon whose stop hook fails, to prove the rest of the roster still leaves.
 */
final class WorkerDeathTestThrowingAgentDaemon extends WorkerDeathTestAgentDaemon
{
    public function onStop(): void
    {
        throw new RuntimeException('stop hook refused');
    }
}

/**
 * Project facade whose registry declares the one instance agent these tests address.
 *
 * Abstract because only its registry constant is read.
 */
abstract class WorkerDeathTestHilos extends Hilos
{
    public const array AGENTS = [
        'worker_death_agent' => [
            AgentRegistryKey::INDEXED => true,
        ],
    ];
}
