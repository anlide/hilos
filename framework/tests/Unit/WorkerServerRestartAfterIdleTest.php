<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\DTO\AgentMessageDTOInterface;
use Hilos\Core\Agent\Exception\WorkerClientNotFoundException;
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

/**
 * An agent stopped for idleness comes back on the next frame addressed to it.
 *
 * This is the half of the policy that makes the other half safe to have: the master starts an
 * agent it has no record of, so stopping one costs nothing but the restart. Nothing here is new
 * code — {@see WorkerServer::sendSignalToAgent()} has always started a missing agent — which is
 * exactly what has to be held: an idle stop that left any trace on the master would make the
 * next frame land on an agent nobody would bring up.
 */
final class WorkerServerRestartAfterIdleTest extends TestCase
{
    private const string INSTANCE_TYPE = 'restart_after_idle_agent';

    private const string INSTANCE_INDEX = '7';

    private const string INSTANCE_AGENT_ID = self::INSTANCE_TYPE . ':' . self::INSTANCE_INDEX;

    /** @var class-string<Hilos> App class bound before this test touched it */
    private string $boundAppClass;

    protected function setUp(): void
    {
        $this->boundAppClass = Hilos::appClass();
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, RestartAfterIdleTestHilos::class);
    }

    protected function tearDown(): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->boundAppClass);
        Hilos::$rt = null;

        parent::tearDown();
    }

    public function testAFrameForAStoppedInstanceAgentStartsItAgain(): void
    {
        $manager = new RestartAfterIdleTestAgentManagerDaemon();
        $server = $this->buildServer($manager);
        $this->registerAgent($server);
        $this->stopAgent($manager);

        $this->sendFrame($server);

        $this->assertSame(
            [self::INSTANCE_AGENT_ID, self::INSTANCE_AGENT_ID],
            $server->startedAgentIds,
            'The second frame must reach a start of its own, the same way the first one did.',
        );
    }

    public function testTheSecondStartIsIndistinguishableFromTheFirst(): void
    {
        $manager = new RestartAfterIdleTestAgentManagerDaemon();
        $server = $this->buildServer($manager);
        $this->registerAgent($server);
        $this->stopAgent($manager);

        $this->sendFrame($server);

        // The instance reads its own state back in onStart(), so a restarted agent is the same
        // agent - nothing of the stopped one is carried over, and nothing has to be.
        $this->assertTrue($manager->hasAgent(self::INSTANCE_AGENT_ID));
    }

    /**
     * Brings the agent up the way a first frame does, leaving the record the master keeps.
     *
     * @param RestartAfterIdleTestWorkerServer $server Server under test
     */
    private function registerAgent(RestartAfterIdleTestWorkerServer $server): void
    {
        $this->sendFrame($server);
        $this->assertSame([self::INSTANCE_AGENT_ID], $server->startedAgentIds);
    }

    /**
     * Drops the master's record of the agent, which is what an idle stop reports back.
     *
     * @param RestartAfterIdleTestAgentManagerDaemon $manager Manager holding the record
     */
    private function stopAgent(RestartAfterIdleTestAgentManagerDaemon $manager): void
    {
        $manager->removeAgent(self::INSTANCE_AGENT_ID);
        $this->assertFalse($manager->hasAgent(self::INSTANCE_AGENT_ID));
    }

    /**
     * Delivers one frame addressed to the instance agent.
     *
     * @param RestartAfterIdleTestWorkerServer $server Server under test
     */
    private function sendFrame(RestartAfterIdleTestWorkerServer $server): void
    {
        try {
            $server->sendSignalToAgent(
                self::INSTANCE_TYPE,
                self::INSTANCE_INDEX,
                new DaemonAgentMessageDTO(self::INSTANCE_AGENT_ID, new SignalDTO(
                    new SignalSource(SignalSource::DAEMON),
                    new SignalType('noop'),
                    new SignalName('noop'),
                    new SignalData([]),
                )),
            );
        } catch (WorkerClientNotFoundException) {
            // Expected: the start is what this test reads, and no worker process is registered
            // here for the frame itself to travel down. Delivery to a cold agent is HIL-629.
        }
    }

    /**
     * @param RestartAfterIdleTestAgentManagerDaemon $manager Agent manager the server routes starts through
     * @return RestartAfterIdleTestWorkerServer Server holding that manager
     */
    private function buildServer(RestartAfterIdleTestAgentManagerDaemon $manager): RestartAfterIdleTestWorkerServer
    {
        // Skip the constructor: it reads worker env and creates a log directory that this test
        // does not exercise. Only the agent manager is needed.
        $server = new ReflectionClass(RestartAfterIdleTestWorkerServer::class)->newInstanceWithoutConstructor();

        new ReflectionProperty(WorkerServer::class, 'agentManager')->setValue($server, $manager);
        $server->managerDouble = $manager;

        return $server;
    }
}

/**
 * Worker server that records every start and stops short of picking a worker.
 *
 * The link to a worker is what this test has no use for: the question is whether the frame
 * reaches a start at all, and a real selection would need a registered worker process.
 */
final class RestartAfterIdleTestWorkerServer extends WorkerServer
{
    /** @var list<string> Agent ids this server was asked to start, in order */
    public array $startedAgentIds = [];

    /** The same manager the base class holds privately, so the start below can reach it. */
    public RestartAfterIdleTestAgentManagerDaemon $managerDouble;

    protected function startAgent(string $agentType, ?string $agentIndex = null): void
    {
        $agentId = $this->buildAgentId($agentType, $agentIndex);
        $this->startedAgentIds[] = $agentId;
        $this->managerDouble->addAgent(
            $agentId,
            $this->managerDouble->instantiateAgentDaemon($agentType, $agentIndex),
            0,
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
final class RestartAfterIdleTestAgentManagerDaemon extends AgentManagerDaemon
{
    /**
     * @param string $agentType Agent type that was asked for
     * @param ?string $agentIndex Agent index that was asked for
     * @return AgentDaemonInterface Daemon carrying nothing but its index
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        return new RestartAfterIdleTestAgentDaemon($agentIndex);
    }
}

/**
 * Agent daemon that declares nothing: only the registry decides its fate.
 */
final class RestartAfterIdleTestAgentDaemon extends TopologyTestAgentDaemon
{
    public function __construct(?string $agentIndex)
    {
        $this->agentIndex = $agentIndex;
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
 * Project facade whose registry declares one instance agent with an idle window.
 *
 * Abstract because only its registry constant is read: the restart never builds a database.
 */
abstract class RestartAfterIdleTestHilos extends Hilos
{
    public const array AGENTS = [
        'restart_after_idle_agent' => [
            AgentRegistryKey::INDEXED => true,
            AgentRegistryKey::IDLE_TIMEOUT => 240,
        ],
    ];
}
