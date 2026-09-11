<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\ProtectedMode;

use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Worker\DTO\WorkerAgentStartedDTO;
use Hilos\Tests\Unit\TopologyTestAgentDaemon;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * Tests what the freeze gate is told is still coming up ({@see WorkerServer::agentsStillStarting()}).
 *
 * The gate holds a new freeze while any start is in flight, because a freeze that lands between a
 * start and its report costs the node that agent. So the question this answers decides how long a
 * freeze waits, and the wrong answer is not a wrong number - it is a wait that can never end.
 *
 * The case that made it wrong: a start that finds no free worker throws and leaves its record on
 * the roster, so the node holds an agent no worker was ever asked to run. Nothing will ever report
 * it. Counted as a start in flight, it made every freeze afterwards wait the gate's whole deadline
 * and then go in on top of it - nine times in one measured run, always on the same three agents.
 */
final class AgentsStillStartingTest extends TestCase
{
    public function testAnAgentHandedToAWorkerAndNotYetReportedIsStillStarting(): void
    {
        $manager = new StillStartingTestAgentManagerDaemon();
        $server = $this->serverWith($manager);
        $this->register($manager, 'in_flight', linked: true);

        $this->assertSame(['in_flight'], $server->agentsStillStarting());
    }

    public function testAnAgentLinkedToNoWorkerIsNotStartingBecauseNobodyWasAsked(): void
    {
        $manager = new StillStartingTestAgentManagerDaemon();
        $server = $this->serverWith($manager);
        $this->register($manager, 'never_placed', linked: false);

        $this->assertSame([], $server->agentsStillStarting());
    }

    public function testAnAgentThatReportedIsNotStartingEither(): void
    {
        $manager = new StillStartingTestAgentManagerDaemon();
        $server = $this->serverWith($manager);
        $this->register($manager, 'up_and_running', linked: true);
        $manager->handleAgentStarted(new WorkerAgentStartedDTO('up_and_running', 'up_and_running'));

        $this->assertSame([], $server->agentsStillStarting());
    }

    public function testOnlyTheOneOnTheWireIsNamedAmongTheThree(): void
    {
        $manager = new StillStartingTestAgentManagerDaemon();
        $server = $this->serverWith($manager);
        $this->register($manager, 'never_placed', linked: false);
        $this->register($manager, 'in_flight', linked: true);
        $this->register($manager, 'up_and_running', linked: true);
        $manager->handleAgentStarted(new WorkerAgentStartedDTO('up_and_running', 'up_and_running'));

        $this->assertSame(['in_flight'], $server->agentsStillStarting());
    }

    /**
     * Puts one agent on the roster, with or without the worker link a start gives it.
     *
     * @param AgentManagerDaemon $manager Roster to register into
     * @param string $agentId Agent id to register
     * @param bool $linked Whether a worker was asked to run it
     */
    private function register(AgentManagerDaemon $manager, string $agentId, bool $linked): void
    {
        $daemon = new StillStartingTestAgentDaemon();

        if ($linked) {
            $client = new ReflectionClass(StillStartingTestWorkerClient::class)->newInstanceWithoutConstructor();
            $client->setWorkerIndex(1);
            $client->setIsMonopolistic(true);
            $daemon->setWorkerClient($client);
        }

        $manager->addAgent($agentId, $daemon, 1, true);
    }

    /**
     * @param AgentManagerDaemon $manager Roster the server reads
     * @return WorkerServer Server carrying that roster and nothing else
     */
    private function serverWith(AgentManagerDaemon $manager): WorkerServer
    {
        // Skip the constructor: it reads worker env and creates a log directory this test does
        // not exercise.
        $server = new ReflectionClass(StillStartingTestWorkerServer::class)->newInstanceWithoutConstructor();
        new ReflectionProperty(WorkerServer::class, 'agentManager')->setValue($server, $manager);

        return $server;
    }
}

/**
 * Worker server that carries a roster and nothing else.
 */
final class StillStartingTestWorkerServer extends WorkerServer
{
    protected function onStart(): void
    {
        // Not used in this test
    }
}

/**
 * Worker client that carries nothing but the index and kind it reports.
 */
final class StillStartingTestWorkerClient extends WorkerClient
{
    public function __construct()
    {
    }
}

/**
 * Agent manager that registers what the test hands it and builds nothing itself.
 */
final class StillStartingTestAgentManagerDaemon extends AgentManagerDaemon
{
    /**
     * @param string $agentType Agent type that was asked for
     * @param ?string $agentIndex Agent index that was asked for
     * @return AgentDaemonInterface Never built: this test registers its agents by hand
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new LogicException('This test registers agents directly.');
    }
}

/**
 * Agent daemon that carries only the worker link the test gives it.
 */
final class StillStartingTestAgentDaemon extends TopologyTestAgentDaemon
{
    public function __construct()
    {
    }
}
