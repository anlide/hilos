<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalType;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\Socket\Worker\DTO\AgentStartDTO;
use Hilos\Socket\Worker\DTO\AgentStopDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Socket\Worker\WorkerDaemonClient;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for worker-side agent stop cleanup.
 */
final class WorkerManagerStopCleanupTest extends TestCase
{
    public function tearDown(): void
    {
        foreach (['', ':1', ':2'] as $indexSuffix) {
            TruthSourceRegistry::unregisterAgent(WorkerManagerStopCleanupTestAgent::AGENT_TYPE . $indexSuffix);
            RtTruthSourceRegistry::unregisterAgent(WorkerManagerStopCleanupTestAgent::AGENT_TYPE . $indexSuffix);
        }

        parent::tearDown();
    }

    public function testAgentStopUnregistersTruthSourcesAfterStopHookThrows(): void
    {
        $agent = new WorkerManagerStopCleanupTestAgent();
        $manager = new WorkerManagerStopCleanupTestManager($agent);

        $manager->handleDaemonMessage(new AgentStartDTO(WorkerManagerStopCleanupTestAgent::AGENT_TYPE));

        $this->assertTrue(TruthSourceRegistry::hasTruthSource($agent->dbCollection()));
        $this->assertTrue(RtTruthSourceRegistry::hasTruthSource($agent->rtCollection()));

        $manager->handleDaemonMessage(new AgentStopDTO(WorkerManagerStopCleanupTestAgent::AGENT_TYPE));

        $this->assertTrue($agent->sawDbTruthSourceOnStop);
        $this->assertTrue($agent->sawRtTruthSourceOnStop);
        $this->assertFalse(TruthSourceRegistry::hasTruthSource($agent->dbCollection()));
        $this->assertFalse(RtTruthSourceRegistry::hasTruthSource($agent->rtCollection()));
    }

    public function testAgentStopRemovesAgentWhenStopHookThrows(): void
    {
        $agent = new WorkerManagerStopCleanupTestAgent();
        $manager = new WorkerManagerStopCleanupTestManager($agent);

        $manager->handleDaemonMessage(new AgentStartDTO(WorkerManagerStopCleanupTestAgent::AGENT_TYPE));
        $manager->handleDaemonMessage(new AgentStopDTO(WorkerManagerStopCleanupTestAgent::AGENT_TYPE));

        $this->assertFalse($manager->hostsAgent(WorkerManagerStopCleanupTestAgent::AGENT_TYPE));
    }

    public function testCleanupStopsRemainingAgentsAndClosesTransportAfterStopHookThrows(): void
    {
        $failing = new WorkerManagerStopCleanupTestAgent('1');
        $surviving = new WorkerManagerStopCleanupTestAgent('2', throwOnStop: false);
        $manager = new WorkerManagerStopCleanupTestManager($failing, $surviving);
        $client = new WorkerManagerStopCleanupTestClient();
        $manager->attachClient($client);

        $manager->handleDaemonMessage(new AgentStartDTO($failing->getId()));
        $manager->handleDaemonMessage(new AgentStartDTO($surviving->getId()));

        $manager->runCleanup();

        $this->assertTrue($surviving->stopHookCalled);
        $this->assertFalse($manager->hostsAgent($failing->getId()));
        $this->assertFalse($manager->hostsAgent($surviving->getId()));
        $this->assertSame(1, $client->closeCount);
    }

    public function testHandshakeValidationExceptionDoesNotEscapeWorkerMessage(): void
    {
        $agent = new WorkerManagerStopCleanupTestAgent();
        $agent->handshakeException = new ValidationException('bad handshake');
        $manager = new WorkerManagerStopCleanupTestManager($agent);

        $manager->handleDaemonMessage(new AgentStartDTO(WorkerManagerStopCleanupTestAgent::AGENT_TYPE));
        $manager->handleDaemonMessage(new DaemonAgentMessageDTO(
            WorkerManagerStopCleanupTestAgent::AGENT_TYPE,
            new SignalDTO(
                new SignalSource(SignalSource::WEBSOCKET),
                new SignalType(SignalTypeConstants::HANDSHAKE),
                new SignalName(SignalTypeConstants::HANDSHAKE),
                new WebSocketHandshakeSignalDTO(
                    headers: [],
                    acceptKey: 'unit-handshake-ak',
                    cookies: [],
                    clientIp: '127.0.0.1',
                    queryParams: RequestQueryParams::empty(),
                ),
            ),
        ));

        $this->assertSame(1, $agent->handshakeCallCount);
    }
}

final class WorkerManagerStopCleanupTestManager extends WorkerManager
{
    /** @var list<WorkerManagerStopCleanupTestAgent> Agents this manager hands out, in start order */
    private readonly array $testAgents;

    public function __construct(WorkerManagerStopCleanupTestAgent ...$testAgents)
    {
        $this->testAgents = $testAgents;

        parent::__construct(1);
    }

    /**
     * Puts a daemon client in place without opening a real connection.
     *
     * @param WorkerDaemonClient $client Client stub counting close() calls
     */
    public function attachClient(WorkerDaemonClient $client): void
    {
        $this->daemonClient = $client;
    }

    /**
     * @param string $agentId Agent id to look for
     * @return bool True while the manager still hosts that agent
     */
    public function hostsAgent(string $agentId): bool
    {
        return $this->agentManager->hasAgent($agentId);
    }

    /**
     * Runs the worker shutdown path the main loop runs after it exits.
     */
    public function runCleanup(): void
    {
        $this->cleanup();
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    protected function createAgentManager(): AgentManager
    {
        return new WorkerManagerStopCleanupTestAgentManager(...$this->testAgents);
    }
}

final class WorkerManagerStopCleanupTestAgentManager extends AgentManager
{
    /** @var array<string, WorkerManagerStopCleanupTestAgent> Agents keyed by their own agent index */
    private readonly array $testAgents;

    public function __construct(WorkerManagerStopCleanupTestAgent ...$testAgents)
    {
        $keyed = [];
        foreach ($testAgents as $agent) {
            $keyed[(string)$agent->getIndex()] = $agent;
        }
        $this->testAgents = $keyed;
    }

    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        return $this->testAgents[(string)$agentIndex]
            ?? throw new RuntimeException("The test manager has no agent for index '{$agentIndex}'.");
    }
}

final class WorkerManagerStopCleanupTestAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'unit_stop_cleanup';
    public const string DB_COLLECTION = 'unit_stop_cleanup_db';
    public const string RT_COLLECTION = 'unit_stop_cleanup_rt';

    public bool $sawDbTruthSourceOnStop = false;
    public bool $sawRtTruthSourceOnStop = false;
    public bool $stopHookCalled = false;
    public int $handshakeCallCount = 0;
    public ?ValidationException $handshakeException = null;

    /**
     * @param ?string $agentIndex Agent index, so one test can host more than one instance
     * @param bool $throwOnStop Whether the stop hook fails, the case under containment
     */
    public function __construct(
        ?string $agentIndex = null,
        private readonly bool $throwOnStop = true,
    ) {
        $this->agentIndex = $agentIndex;
    }

    /**
     * @return string Truth-source collection owned by this instance alone
     */
    public function dbCollection(): string
    {
        return self::DB_COLLECTION . (string)$this->agentIndex;
    }

    /**
     * @return string Runtime truth-source collection owned by this instance alone
     */
    public function rtCollection(): string
    {
        return self::RT_COLLECTION . (string)$this->agentIndex;
    }

    public function onStart(): void
    {
        $this->registerDbTruthSource($this->dbCollection());
        $this->registerRtTruthSource($this->rtCollection());
    }

    public function onStop(): void
    {
        $this->stopHookCalled = true;
        $this->sawDbTruthSourceOnStop = TruthSourceRegistry::hasTruthSource($this->dbCollection());
        $this->sawRtTruthSourceOnStop = RtTruthSourceRegistry::hasTruthSource($this->rtCollection());

        if ($this->throwOnStop) {
            throw new RuntimeException('stop hook failed');
        }
    }

    public function onSignalHandshake(WebSocketHandshakeSignalDTO $data, string $source, string $name): void
    {
        $this->handshakeCallCount++;

        if ($this->handshakeException !== null) {
            throw $this->handshakeException;
        }
    }
}

/**
 * Daemon client stub that only counts how often the worker closed it.
 */
final class WorkerManagerStopCleanupTestClient extends WorkerDaemonClient
{
    /** How many times cleanup closed this client. */
    public int $closeCount = 0;

    public function close(): void
    {
        $this->closeCount++;
    }
}
