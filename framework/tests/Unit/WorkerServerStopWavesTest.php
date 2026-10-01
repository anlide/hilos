<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\HilosAgentType;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\DTO\AgentMessageDTOInterface;
use Hilos\Core\Process;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Server\WorkerServer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * A node's stop in two waves (HIL-1154): the worker of an agent that stops after the others - the
 * node's analytics journal - is held until the others are gone, then its agent is stopped over
 * the connection, and only then the worker gets SIGTERM.
 */
final class WorkerServerStopWavesTest extends TestCase
{
    private const int PLAIN_WORKER = 1;

    private const int JOURNAL_WORKER = 2;

    protected function setUp(): void
    {
        StopWavesTestLog::$events = [];
    }

    public function testTheHeldWorkerWaitsForTheOthersAndItsAgentStopsBeforeItsSigterm(): void
    {
        $server = $this->buildServer(withJournal: true);

        $server->stop();
        $this->assertSame(['sigterm:' . self::PLAIN_WORKER], StopWavesTestLog::$events);

        // The first wave is still running: nothing moves.
        $server->advance();
        $server->advance();
        $this->assertSame(['sigterm:' . self::PLAIN_WORKER], StopWavesTestLog::$events);

        $this->workerLeaves($server, $this->regularKey());

        // The pass in which the last of the first wave left only notes it: its frames go out at its end.
        $server->advance();
        $this->assertSame(['sigterm:' . self::PLAIN_WORKER], StopWavesTestLog::$events);

        $server->advance();
        $server->advance();
        $server->advance();
        $this->assertSame([
            'sigterm:' . self::PLAIN_WORKER,
            'agent_stop:' . HilosAgentType::HILOS_ANALYTICS_JOURNAL,
            'sigterm:' . self::JOURNAL_WORKER,
        ], StopWavesTestLog::$events);
    }

    /**
     * A worker that has exited may still have its last batch in its socket: the master reads a
     * connection a buffer at a time. The second wave waits for the connection, not the process.
     */
    public function testTheSecondWaveWaitsForTheFirstWavesConnectionsToClose(): void
    {
        $server = $this->buildServer(withJournal: true);
        $connection = new ReflectionClass(StopWavesTestWorkerClient::class)->newInstanceWithoutConstructor();
        $connection->setWorkerIndex(self::PLAIN_WORKER);
        $clients = new ReflectionProperty(WorkerServer::class, 'clients');
        $clients->setValue($server, [$connection]);

        $server->stop();
        $this->workerLeaves($server, $this->regularKey());
        $server->advance();
        $server->advance();
        $server->advance();
        $this->assertSame(['sigterm:' . self::PLAIN_WORKER], StopWavesTestLog::$events);

        $clients->setValue($server, []);
        $server->advance();
        $server->advance();
        $server->advance();
        $this->assertSame([
            'sigterm:' . self::PLAIN_WORKER,
            'agent_stop:' . HilosAgentType::HILOS_ANALYTICS_JOURNAL,
            'sigterm:' . self::JOURNAL_WORKER,
        ], StopWavesTestLog::$events);
    }

    public function testWithoutAHeldAgentTheStopIsOneWaveAsItWas(): void
    {
        $server = $this->buildServer(withJournal: false);

        $server->stop();
        $server->advance();
        $server->advance();

        $this->assertSame(['sigterm:' . self::PLAIN_WORKER, 'sigterm:' . self::JOURNAL_WORKER], StopWavesTestLog::$events);
    }

    /**
     * @param bool $withJournal Whether the monopolistic worker hosts the journal agent or a plain one
     * @return StopWavesTestWorkerServer Server with a regular and a monopolistic worker, one agent on each
     */
    private function buildServer(bool $withJournal): StopWavesTestWorkerServer
    {
        // Skip the constructor: it reads worker env and creates a log directory this test does not exercise.
        $server = new ReflectionClass(StopWavesTestWorkerServer::class)->newInstanceWithoutConstructor();

        $agents = new StopWavesTestAgentManagerDaemon();
        $agents->addAgent('plain_agent', new StopWavesTestAgentDaemon(false), self::PLAIN_WORKER, false);
        $agents->addAgent(HilosAgentType::HILOS_ANALYTICS_JOURNAL, new StopWavesTestAgentDaemon($withJournal), self::JOURNAL_WORKER, true);
        new ReflectionProperty(WorkerServer::class, 'agentManager')->setValue($server, $agents);

        new ReflectionProperty(WorkerServer::class, 'workers')->setValue($server, [
            $this->regularKey() => $this->worker(WorkerConstants::TYPE_REGULAR, self::PLAIN_WORKER),
            WorkerConstants::TYPE_MONOPOLISTIC . WorkerConstants::KEY_SEPARATOR . self::JOURNAL_WORKER
                => $this->worker(WorkerConstants::TYPE_MONOPOLISTIC, self::JOURNAL_WORKER),
        ]);

        return $server;
    }

    /**
     * @param string $type Worker type
     * @param int $index Worker index
     * @return array<string, Process|string|int> Worker record as the server keeps it
     */
    private function worker(string $type, int $index): array
    {
        $process = new ReflectionClass(StopWavesTestProcess::class)->newInstanceWithoutConstructor();
        $process->index = $index;

        return [
            WorkerConstants::FIELD_WORKER_PROCESS => $process,
            WorkerConstants::FIELD_WORKER_TYPE => $type,
            WorkerConstants::FIELD_WORKER_INDEX => $index,
        ];
    }

    /**
     * @return string Key of the regular worker
     */
    private function regularKey(): string
    {
        return WorkerConstants::TYPE_REGULAR . WorkerConstants::KEY_SEPARATOR . self::PLAIN_WORKER;
    }

    /**
     * @param WorkerServer $server Server whose worker exited
     * @param string $key Key of the worker that exited
     */
    private function workerLeaves(WorkerServer $server, string $key): void
    {
        $workers = new ReflectionProperty(WorkerServer::class, 'workers');
        $remaining = $workers->getValue($server);
        unset($remaining[$key]);
        $workers->setValue($server, $remaining);
    }
}

/**
 * What the server did, in order.
 */
final class StopWavesTestLog
{
    /** @var list<string> */
    public static array $events = [];
}

/**
 * Worker server that records the agent stops it sends and lets the test drive the second wave.
 */
final class StopWavesTestWorkerServer extends WorkerServer
{
    public function advance(): void
    {
        $this->advanceSecondStopWave();
    }

    protected function stopAgent(string $agentType, ?string $agentIndex = null): void
    {
        StopWavesTestLog::$events[] = 'agent_stop:' . $agentType;
    }

    protected function onStart(): void
    {
        // Not used in this test
    }
}

/**
 * Worker client that carries nothing but the index it reports.
 */
final class StopWavesTestWorkerClient extends WorkerClient
{
    public function __construct()
    {
    }
}

/**
 * Process that records its SIGTERM instead of sending one.
 */
final class StopWavesTestProcess extends Process
{
    public int $index = 0;

    public function stop(?float $shutdownTimeout = null): void
    {
        StopWavesTestLog::$events[] = 'sigterm:' . $this->index;
    }
}

/**
 * Agent manager whose factory is never asked.
 */
final class StopWavesTestAgentManagerDaemon extends AgentManagerDaemon
{
    /**
     * @param string $agentType Agent type that was asked for
     * @param ?string $agentIndex Agent index that was asked for
     * @return AgentDaemonInterface A plain daemon
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        return new StopWavesTestAgentDaemon(false);
    }
}

/**
 * Agent daemon that stops after the other workers, or does not.
 */
final class StopWavesTestAgentDaemon extends AbstractAgentDaemon
{
    public function __construct(private readonly bool $stopsLast)
    {
    }

    public function requiresMonopolisticProcess(): bool
    {
        return $this->stopsLast;
    }

    public function stopsAfterOtherWorkers(): bool
    {
        return $this->stopsLast;
    }

    /**
     * @param AgentMessageDTOInterface $message Message that would go to a user; unused here
     */
    public function sendToUser(AgentMessageDTOInterface $message): void
    {
        // Not used in this test
    }
}
