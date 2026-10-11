<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Daemon\AgentStartSink;
use Hilos\Core\Agent\DTO\AgentMessageDTOInterface;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Router\SignalRouter;
use Hilos\Hilos;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Server\AwaitingStopReportStart;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Worker\DTO\WorkerAgentStoppedDTO;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * A stop report from an old worker instance must precede a new start of the same agent.
 */
final class WorkerServerStartAfterStopTest extends TestCase
{
    private const string AGENT_TYPE = 'unit_start_after_stop';

    private const int ORIGINAL_WORKER = 17;

    private const int OTHER_WORKER = 18;

    /** @var class-string<Hilos> App class bound before this test touched it */
    private string $boundAppClass;

    protected function setUp(): void
    {
        $this->boundAppClass = Hilos::appClass();
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, StartAfterStopTestHilos::class);
        Hilos::$cluster = null;
        Hilos::$rt = null;
    }

    protected function tearDown(): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->boundAppClass);

        parent::tearDown();
    }

    public function testLateStopReportPrecedesTheNewInstanceAndTheOldWorkerIsPreferred(): void
    {
        [$server, $manager, $original] = $this->runningAgent();
        $other = $server->addWorker(self::OTHER_WORKER);

        $server->restartAgentHere(self::AGENT_TYPE, null, false);
        $server->startPublic();
        $this->assertTrue($server->isAgentAwaitingStopReport(self::AGENT_TYPE));
        $this->assertFalse($manager->hasAgent(self::AGENT_TYPE));
        $this->assertCount(1, $original->startedAgentTypes);
        $this->assertSame([], $other->startedAgentTypes);

        $original->reportStop();
        $manager->handleAgentStopped(new WorkerAgentStoppedDTO(self::AGENT_TYPE));
        $server->advancePublic();

        $this->assertFalse($server->isAgentAwaitingStopReport(self::AGENT_TYPE));
        $this->assertSame([self::AGENT_TYPE, self::AGENT_TYPE], $original->startedAgentTypes);
        $this->assertSame([], $other->startedAgentTypes);
        $this->assertSame(self::ORIGINAL_WORKER, $manager->getAgentWorkerId(self::AGENT_TYPE));
    }

    public function testPlacementAcceptedDuringStopWaitKeepsItsSanction(): void
    {
        [$server] = $this->runningAgent();
        $server->stopPublic();
        $server->startPublic();

        $this->assertNull($server->executePlacement(self::AGENT_TYPE, null));
        $awaiting = new ReflectionProperty(WorkerServer::class, 'agentsAwaitingStopReport')->getValue($server);
        $this->assertInstanceOf(AwaitingStopReportStart::class, $awaiting[self::AGENT_TYPE]);
        $this->assertTrue($awaiting[self::AGENT_TYPE]->placedByLeader);
    }

    public function testRestartRefusesWhenTheOldRosterRecordCannotBeStopped(): void
    {
        [$server] = $this->runningAgent();
        $server->disconnectWorker(self::ORIGINAL_WORKER);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('could not be stopped');
        $server->restartAgentHere(self::AGENT_TYPE, null, false);
    }

    public function testAClosedLinkEndsTheStopWaitAndStartsOnAnotherWorker(): void
    {
        [$server, $manager] = $this->runningAgent();
        $server->stopPublic();
        $server->startPublic();
        $other = $server->addWorker(self::OTHER_WORKER);
        $server->disconnectWorker(self::ORIGINAL_WORKER);

        $server->advancePublic();

        $this->assertFalse($server->isAgentAwaitingStopReport(self::AGENT_TYPE));
        $this->assertSame([self::AGENT_TYPE], $other->startedAgentTypes);
        $this->assertSame(self::OTHER_WORKER, $manager->getAgentWorkerId(self::AGENT_TYPE));
    }

    public function testGateRefusalAfterStopReportAnswersTheDeferredStart(): void
    {
        [$server, $manager, $original] = $this->runningAgent();
        $sink = new StartAfterStopTestStartSink();
        $manager->registerAgentStartSink($sink);
        $server->stopPublic();
        $server->startPublic();
        $server->refuseRtClaim(self::AGENT_TYPE, null);
        $original->reportStop();

        $server->advancePublic();

        $this->assertSame([self::AGENT_TYPE], $sink->failedAgentIds);
        $this->assertFalse($manager->hasAgent(self::AGENT_TYPE));
    }

    public function testAnotherStartAfterTheReportLeavesTheWaitForItsVerdict(): void
    {
        [$server, $manager, $original] = $this->runningAgent();
        $sink = new StartAfterStopTestStartSink();
        $manager->registerAgentStartSink($sink);
        $server->stopPublic();
        $server->startPublic();
        $server->refuseRtClaim(self::AGENT_TYPE, null);
        $original->reportStop();

        $server->startPublic();
        $this->assertTrue($server->isAgentAwaitingStopReport(self::AGENT_TYPE));
        $server->advancePublic();

        $this->assertSame([self::AGENT_TYPE], $sink->failedAgentIds);
    }

    public function testAStartFailureAfterTheOldLinkClosesAnswersTheDeferredStart(): void
    {
        [$server, $manager] = $this->runningAgent();
        $sink = new StartAfterStopTestStartSink();
        $manager->registerAgentStartSink($sink);
        $server->stopPublic();
        $server->startPublic();
        $server->disconnectWorker(self::ORIGINAL_WORKER);

        $server->advancePublic();

        $this->assertSame([self::AGENT_TYPE], $sink->failedAgentIds);
        $this->assertFalse($manager->hasAgent(self::AGENT_TYPE));
    }

    public function testStopCancelsAStartWaitingForItsEarlierStopReport(): void
    {
        [$server, , $original] = $this->runningAgent();
        $server->stopPublic();
        $server->startPublic();

        $server->stopPublic();
        $original->reportStop();
        $server->advancePublic();

        $this->assertFalse($server->isAgentAwaitingStopReport(self::AGENT_TYPE));
        $this->assertSame([self::AGENT_TYPE], $original->startedAgentTypes);
    }

    public function testDeferredStartCountsAsUnderWayForTheMastersFrameHold(): void
    {
        [$server, $manager] = $this->runningAgent();
        $server->stopPublic();
        $server->startPublic();
        $this->assertTrue($server->isAgentAwaitingStopReport(self::AGENT_TYPE));
        $daemon = new ReflectionClass(StartAfterStopTestDaemonManager::class)->newInstanceWithoutConstructor();
        new ReflectionProperty(DaemonManager::class, 'agentManagerDaemon')->setValue($daemon, $manager);

        $this->assertTrue(new ReflectionClass(DaemonManager::class)
            ->getMethod('isAgentStartUnderWay')->invoke($daemon, $server, self::AGENT_TYPE));
    }

    /**
     * @return array{StartAfterStopTestWorkerServer, StartAfterStopTestAgentManagerDaemon, StartAfterStopTestWorkerClient}
     *     Server with one agent on its original worker
     */
    private function runningAgent(): array
    {
        $manager = new StartAfterStopTestAgentManagerDaemon();
        $server = new ReflectionClass(StartAfterStopTestWorkerServer::class)->newInstanceWithoutConstructor();
        new ReflectionProperty(WorkerServer::class, 'agentManager')->setValue($server, $manager);
        $original = $server->addWorker(self::ORIGINAL_WORKER);
        $server->startPublic();

        return [$server, $manager, $original];
    }
}

final class StartAfterStopTestWorkerServer extends WorkerServer
{
    public function startPublic(): void
    {
        $this->startAgent('unit_start_after_stop');
    }

    public function stopPublic(): void
    {
        $this->stopAgent('unit_start_after_stop');
    }

    public function advancePublic(): void
    {
        new ReflectionClass(WorkerServer::class)->getMethod('advanceAgentsAwaitingStopReport')->invoke($this);
    }

    public function addWorker(int $workerIndex): StartAfterStopTestWorkerClient
    {
        $client = new ReflectionClass(StartAfterStopTestWorkerClient::class)->newInstanceWithoutConstructor();
        $client->setWorkerIndex($workerIndex);
        $this->clients[] = $client;

        return $client;
    }

    public function disconnectWorker(int $workerIndex): void
    {
        $this->clients = array_values(array_filter(
            $this->clients,
            static fn(WorkerClient $client): bool => $client->getWorkerIndex() !== $workerIndex,
        ));
    }

    protected function onStart(): void
    {
    }
}

final class StartAfterStopTestWorkerClient extends WorkerClient
{
    /** @var list<string> Agent types this worker was told to start */
    public array $startedAgentTypes = [];

    public function sendAgentStart(string $agentType, ?string $agentIndex = null, array $liveAcceptKeys = []): void
    {
        $this->startedAgentTypes[] = $agentType;
    }

    public function send(string $message): void
    {
    }

    public function reportStop(): void
    {
        new ReflectionProperty(WorkerClient::class, 'stopsAwaitingReport')->setValue($this, []);
    }
}

final class StartAfterStopTestAgentManagerDaemon extends AgentManagerDaemon
{
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        return new StartAfterStopTestAgentDaemon();
    }
}

final class StartAfterStopTestAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = 'unit_start_after_stop';

    public function requiresMonopolisticProcess(): bool
    {
        return false;
    }

    public function sendToUser(AgentMessageDTOInterface $message): void
    {
    }
}

abstract class StartAfterStopTestHilos extends Hilos
{
    public const array AGENTS = [StartAfterStopTestAgentDaemon::AGENT_TYPE => []];
}

final class StartAfterStopTestDaemonManager extends DaemonManager
{
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new StartAfterStopTestAgentManagerDaemon();
    }
}

final class StartAfterStopTestStartSink implements AgentStartSink
{
    /** @var list<string> Agents whose start failed */
    public array $failedAgentIds = [];

    public function onAgentStarted(string $agentId): void
    {
    }

    public function onAgentStartFailed(string $agentId, string $reason): void
    {
        $this->failedAgentIds[] = $agentId;
    }

    public function onAgentStopped(string $agentId): void
    {
    }
}
