<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\DTO\AgentMessageDTOInterface;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Agent\Exception\NoSuitableWorkerException;
use Hilos\Core\Daemon\ContainedFailure;
use Hilos\Core\Daemon\ContainedFailureSink;
use Hilos\Core\Daemon\Master\MasterFailureUnit;
use Hilos\Hilos;
use Hilos\Socket\Server\WorkerServer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * What a start refused for want of a worker leaves behind on this node (HIL-999).
 *
 * The worker pick used to throw with the temporary agent record still registered, so the node
 * held an agent nobody was ever asked to run - the phantom the freeze entry gate waited on in
 * run 0232. The pick now rolls the record back the way the placement and RT-claim gates above it
 * do, and only when the record was not there before: an agent that exists and lost its link is
 * somebody's to restart, not this refusal's to forget.
 *
 * The per-node start was contained before this leaf; what it gains is the project's card, so a
 * project counting refused starts is not handed a partial count.
 */
final class WorkerServerRefusedStartRecordTest extends TestCase
{
    private const string AGENT_TYPE = 'refused';

    private const string PER_NODE_TYPE = 'pernode';

    /** Worker index an unlinked record carries until a worker is picked */
    private const int UNLINKED_WORKER_INDEX = 0;

    /** @var class-string<Hilos> App class bound before this test touched it */
    private string $boundAppClass;

    protected function setUp(): void
    {
        $this->boundAppClass = Hilos::appClass();
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, RefusedStartTestHilos::class);
        Hilos::$cluster = null;
    }

    protected function tearDown(): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->boundAppClass);

        parent::tearDown();
    }

    public function testARefusedStartLeavesNoRecordBehind(): void
    {
        $manager = new RefusedStartTestAgentManagerDaemon();
        $server = $this->buildServer($manager);

        try {
            $server->startAgentPublic(self::AGENT_TYPE);
            $this->fail('No worker is registered, so the start was expected to be refused.');
        } catch (NoSuitableWorkerException) {
            $this->assertFalse($manager->hasAgent(self::AGENT_TYPE));
            $this->assertSame([], $server->agentsStillStarting());
        }
    }

    /**
     * The rollback is for the record this start wrote; one that was already there belongs to an
     * agent that exists and lost its link, and a refused retry must not erase it.
     */
    public function testAnAgentThatExistedBeforeTheRefusedStartKeepsItsRecord(): void
    {
        $manager = new RefusedStartTestAgentManagerDaemon();
        $manager->createAndAddAgent(self::AGENT_TYPE, null, self::UNLINKED_WORKER_INDEX, false);
        $server = $this->buildServer($manager);

        try {
            $server->startAgentPublic(self::AGENT_TYPE);
            $this->fail('No worker is registered, so the start was expected to be refused.');
        } catch (NoSuitableWorkerException) {
            $this->assertTrue($manager->hasAgent(self::AGENT_TYPE));
        }
    }

    public function testARefusedPerNodeStartReachesTheProjectAsACardAddressedByTheAgentId(): void
    {
        $server = $this->buildServer(new RefusedStartTestAgentManagerDaemon());
        $sink = new RefusedStartTestSink();
        $server->setContainedFailureSink($sink);

        $server->startPerNodeAgentsPublic();

        $this->assertCount(1, $sink->reported);
        $this->assertSame(MasterFailureUnit::AGENT_START, $sink->reported[0]->unit);
        $this->assertSame(self::PER_NODE_TYPE, $sink->reported[0]->address);
        $this->assertInstanceOf(NoSuitableWorkerException::class, $sink->reported[0]->failure);
    }

    /**
     * @param AgentManagerDaemon $manager Agent manager the server routes starts through
     * @return RefusedStartTestWorkerServer Server holding that manager and no worker
     */
    private function buildServer(AgentManagerDaemon $manager): RefusedStartTestWorkerServer
    {
        // Skip the constructor: it reads worker env and creates a log directory that this test
        // does not exercise. Only the agent manager is needed.
        $server = new ReflectionClass(RefusedStartTestWorkerServer::class)->newInstanceWithoutConstructor();
        new ReflectionProperty(WorkerServer::class, 'agentManager')->setValue($server, $manager);

        return $server;
    }
}

/**
 * Worker server that exposes the protected start paths for this test.
 */
final class RefusedStartTestWorkerServer extends WorkerServer
{
    /**
     * @param string $agentType Agent type to start
     * @throws AgentDaemonCreationFailedException If the agent daemon cannot be created
     * @throws NoSuitableWorkerException When no worker is registered
     */
    public function startAgentPublic(string $agentType): void
    {
        $this->startAgent($agentType, null);
    }

    public function startPerNodeAgentsPublic(): void
    {
        $this->startPerNodeAgents();
    }

    protected function onStart(): void
    {
    }
}

/**
 * Sink that keeps the cards a server reports instead of handing them to a project.
 */
final class RefusedStartTestSink implements ContainedFailureSink
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
 * Agent manager whose factory answers every type this test declares.
 */
final class RefusedStartTestAgentManagerDaemon extends AgentManagerDaemon
{
    /**
     * @param string $agentType Agent type that was asked for
     * @param ?string $agentIndex Agent index that was asked for
     * @return AgentDaemonInterface Daemon carrying nothing but its index
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        return new RefusedStartTestAgentDaemon($agentIndex);
    }
}

/**
 * Agent daemon that declares nothing and runs in a regular worker.
 */
final class RefusedStartTestAgentDaemon extends AbstractAgentDaemon
{
    public function __construct(?string $agentIndex)
    {
        $this->agentIndex = $agentIndex;
    }

    public function requiresMonopolisticProcess(): bool
    {
        return false;
    }

    /**
     * @param AgentMessageDTOInterface $message Message that would go to a user; unused here
     */
    public function sendToUser(AgentMessageDTOInterface $message): void
    {
    }
}

/**
 * Project facade declaring one leader-hosted agent and one every-node agent.
 *
 * Abstract because only its registry constant is read: the start never builds a database.
 */
abstract class RefusedStartTestHilos extends Hilos
{
    public const array AGENTS = [
        'refused' => [],
        'pernode' => [AgentRegistryKey::SCOPE => AgentScope::NODE],
    ];
}
