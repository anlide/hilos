<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\DTO\AgentMessageDTOInterface;
use Hilos\Hilos;
use Hilos\Socket\Client\ClientInterface;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Server\WorkerServer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * A node-scoped agent lost with its worker is started again; the rest wait to be addressed.
 *
 * Forgetting a dead worker's agents is what lets the master start them when something asks for
 * one, and almost everything is asked for eventually - a frame, an action, or a cron rule,
 * which outlives the agent that registered it. A node replica is the exception the registry
 * declares: it runs on every node, so the start pass is the only thing that ever asks, and
 * without a pass of its own it would stay down until the daemon restarted.
 */
final class WorkerDeathRestartsNodeAgentsTest extends TestCase
{
    private const string NODE_AGENT = 'node_replica_agent';

    private const string ADDRESSED_AGENT = 'addressed_agent';

    /** @var class-string<Hilos> App class bound before this test touched it */
    private string $boundAppClass;

    protected function setUp(): void
    {
        $this->boundAppClass = Hilos::appClass();
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, NodeRestartTestHilos::class);
    }

    protected function tearDown(): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->boundAppClass);

        parent::tearDown();
    }

    public function testLosingAWorkerStartsTheNodeScopedAgentsAgain(): void
    {
        $server = $this->buildServer();

        $server->removeClient($this->workerClient());

        $this->assertSame([self::NODE_AGENT], $server->startedAgentTypes);
    }

    public function testAnAgentThatIsAddressedIsNotStartedByThisPass(): void
    {
        // It comes back when something asks for it, and starting it here would bring up an agent
        // this node may have had no reason to run since.
        $server = $this->buildServer();

        $server->removeClient($this->workerClient());

        $this->assertNotContains(self::ADDRESSED_AGENT, $server->startedAgentTypes);
    }

    public function testANodeThatIsShuttingDownStartsNothing(): void
    {
        // A shutdown that starts what it is about to stop never ends.
        $server = $this->buildServer();
        new ReflectionProperty(WorkerServer::class, 'preparingShutdown')->setValue($server, true);

        $server->removeClient($this->workerClient());

        $this->assertSame([], $server->startedAgentTypes);
    }

    public function testAClientThatNeverRegisteredAsAWorkerStartsNothing(): void
    {
        // It carried no agents, so it lost none.
        $server = $this->buildServer();

        $server->removeClient($this->workerClient(0));

        $this->assertSame([], $server->startedAgentTypes);
    }

    /**
     * @param int $workerIndex Index the client reports, zero for one that never registered
     * @return WorkerClient Client standing in for a worker link that has just closed
     */
    private function workerClient(int $workerIndex = 5): WorkerClient
    {
        $client = new ReflectionClass(NodeRestartTestWorkerClient::class)->newInstanceWithoutConstructor();
        $client->setWorkerIndex($workerIndex);

        return $client;
    }

    /**
     * @return NodeRestartTestWorkerServer Server that records the starts it was asked for
     */
    private function buildServer(): NodeRestartTestWorkerServer
    {
        // Skip the constructor: it reads worker env and creates a log directory this test does
        // not exercise.
        $server = new ReflectionClass(NodeRestartTestWorkerServer::class)->newInstanceWithoutConstructor();

        new ReflectionProperty(WorkerServer::class, 'agentManager')
            ->setValue($server, new NodeRestartTestAgentManagerDaemon());

        return $server;
    }
}

/**
 * Worker server that records every start and stops short of picking a worker.
 */
final class NodeRestartTestWorkerServer extends WorkerServer
{
    /** @var list<string> Agent types this server was asked to start, in order */
    public array $startedAgentTypes = [];

    protected function startAgent(string $agentType, ?string $agentIndex = null): void
    {
        $this->startedAgentTypes[] = $agentType;
    }

    protected function onStart(): void
    {
        // Not used in this test
    }
}

/**
 * Worker client that carries nothing but the index it reports.
 */
final class NodeRestartTestWorkerClient extends WorkerClient
{
    public function __construct()
    {
    }
}

/**
 * Agent manager whose factory answers the types this test declares.
 */
final class NodeRestartTestAgentManagerDaemon extends AgentManagerDaemon
{
    /**
     * @param string $agentType Agent type that was asked for
     * @param ?string $agentIndex Agent index that was asked for
     * @return AgentDaemonInterface Daemon carrying nothing but its index
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        return new NodeRestartTestAgentDaemon($agentIndex);
    }
}

/**
 * Agent daemon that declares nothing: only the registry decides its fate.
 */
final class NodeRestartTestAgentDaemon extends TopologyTestAgentDaemon
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
 * Project facade whose registry declares one node replica and one ordinary agent.
 *
 * Abstract because only its registry constant is read.
 */
abstract class NodeRestartTestHilos extends Hilos
{
    public const array AGENTS = [
        'node_replica_agent' => [
            AgentRegistryKey::SCOPE => AgentScope::NODE,
        ],
        'addressed_agent' => [
            AgentRegistryKey::INDEXED => true,
        ],
    ];
}
