<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Probe;

use Hilos\Cluster\Probe\ClusterProbe;
use Hilos\Constants\HilosAgentType;
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
use Hilos\Hilos;
use Hilos\Socket\Server\WorkerServer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * What the worker server's start does with a cluster probe on a node where none may run.
 *
 * Off a cluster - one node, a Playwright stand, a single-node production - a project that lists
 * a probe must come up exactly as if it did not: no record for an agent nobody will run, and no
 * failure card, since nothing failed. An agent that is not a probe meets none of this and goes on
 * to the worker pick as before, which here, with no worker registered, refuses it.
 */
final class ClusterProbeStartGateTest extends TestCase
{
    private const string PLAIN_TYPE = 'plain';

    private const string PER_NODE_TYPE = 'pernode';

    /** @var class-string<Hilos> App class bound before this test touched it */
    private string $boundAppClass;

    protected function setUp(): void
    {
        $this->boundAppClass = Hilos::appClass();
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, ProbeStartGateTestHilos::class);
        Hilos::$cluster = null;
    }

    protected function tearDown(): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->boundAppClass);

        parent::tearDown();
    }

    public function testAProbeOffAClusterIsPassedOverWithoutARecord(): void
    {
        $manager = new ProbeStartGateTestAgentManagerDaemon();
        $server = $this->buildServer($manager);

        $server->startAgentPublic(HilosAgentType::HILOS_PROBE_DB);

        $this->assertFalse($manager->hasAgent(HilosAgentType::HILOS_PROBE_DB));
        $this->assertSame([], $server->agentsStillStarting());
    }

    public function testAnAgentThatIsNoProbeStartsAsBefore(): void
    {
        $manager = new ProbeStartGateTestAgentManagerDaemon();
        $server = $this->buildServer($manager);

        $this->expectException(NoSuitableWorkerException::class);
        $server->startAgentPublic(self::PLAIN_TYPE);
    }

    public function testAProbeReplicaOffAClusterLeavesNoFailureCard(): void
    {
        $server = $this->buildServer(new ProbeStartGateTestAgentManagerDaemon());
        $sink = new ProbeStartGateTestSink();
        $server->setContainedFailureSink($sink);

        $server->startPerNodeAgentsPublic();

        // The ordinary replica is refused for want of a worker and says so; the two probe
        // replicas are not refused at all - they are not started - so they say nothing.
        $this->assertCount(1, $sink->reported);
        $this->assertSame(self::PER_NODE_TYPE, $sink->reported[0]->address);
    }

    /**
     * @param AgentManagerDaemon $manager Agent manager the server routes starts through
     * @return ProbeStartGateTestWorkerServer Server holding that manager and no worker
     */
    private function buildServer(AgentManagerDaemon $manager): ProbeStartGateTestWorkerServer
    {
        // Skip the constructor: it reads worker env and creates a log directory that this test
        // does not exercise. Only the agent manager is needed.
        $server = new ReflectionClass(ProbeStartGateTestWorkerServer::class)->newInstanceWithoutConstructor();
        new ReflectionProperty(WorkerServer::class, 'agentManager')->setValue($server, $manager);

        return $server;
    }
}

/**
 * Worker server that exposes the protected start paths for this test.
 */
final class ProbeStartGateTestWorkerServer extends WorkerServer
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
final class ProbeStartGateTestSink implements ContainedFailureSink
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
final class ProbeStartGateTestAgentManagerDaemon extends AgentManagerDaemon
{
    /**
     * @param string $agentType Agent type that was asked for
     * @param ?string $agentIndex Agent index that was asked for
     * @return AgentDaemonInterface Daemon carrying nothing but its index
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        return new ProbeStartGateTestAgentDaemon($agentIndex);
    }
}

/**
 * Agent daemon that declares nothing and runs in a regular worker.
 */
final class ProbeStartGateTestAgentDaemon extends AbstractAgentDaemon
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
 * Project facade listing the two probe replicas beside an ordinary agent and an ordinary replica.
 *
 * Abstract because only its registry constant is read: the start never builds a database.
 */
abstract class ProbeStartGateTestHilos extends Hilos
{
    public const array AGENTS = [
        HilosAgentType::HILOS_PROBE_DB => ClusterProbe::AGENTS[HilosAgentType::HILOS_PROBE_DB],
        HilosAgentType::HILOS_PROBE_RT_SET => ClusterProbe::AGENTS[HilosAgentType::HILOS_PROBE_RT_SET],
        'plain' => [],
        'pernode' => [AgentRegistryKey::SCOPE => AgentScope::NODE],
    ];
}
