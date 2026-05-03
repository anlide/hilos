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
        TruthSourceRegistry::unregisterAgent(WorkerManagerStopCleanupTestAgent::AGENT_TYPE);
        RtTruthSourceRegistry::unregisterAgent(WorkerManagerStopCleanupTestAgent::AGENT_TYPE);

        parent::tearDown();
    }

    public function testAgentStopUnregistersTruthSourcesAfterStopHookThrows(): void
    {
        $agent = new WorkerManagerStopCleanupTestAgent();
        $manager = new WorkerManagerStopCleanupTestManager($agent);

        $manager->handleDaemonMessage(new AgentStartDTO(WorkerManagerStopCleanupTestAgent::AGENT_TYPE));

        $this->assertTrue(TruthSourceRegistry::hasTruthSource(WorkerManagerStopCleanupTestAgent::DB_COLLECTION));
        $this->assertTrue(RtTruthSourceRegistry::hasTruthSource(WorkerManagerStopCleanupTestAgent::RT_COLLECTION));

        try {
            $manager->handleDaemonMessage(new AgentStopDTO(WorkerManagerStopCleanupTestAgent::AGENT_TYPE));
            $this->fail('Expected the test agent stop hook to throw.');
        } catch (RuntimeException $e) {
            $this->assertSame('stop hook failed', $e->getMessage());
        }

        $this->assertTrue($agent->sawDbTruthSourceOnStop);
        $this->assertTrue($agent->sawRtTruthSourceOnStop);
        $this->assertFalse(TruthSourceRegistry::hasTruthSource(WorkerManagerStopCleanupTestAgent::DB_COLLECTION));
        $this->assertFalse(RtTruthSourceRegistry::hasTruthSource(WorkerManagerStopCleanupTestAgent::RT_COLLECTION));
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
    public function __construct(
        private readonly WorkerManagerStopCleanupTestAgent $testAgent,
    ) {
        parent::__construct(1);
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    protected function createAgentManager(): AgentManager
    {
        return new WorkerManagerStopCleanupTestAgentManager($this->testAgent);
    }
}

final class WorkerManagerStopCleanupTestAgentManager extends AgentManager
{
    public function __construct(
        private readonly WorkerManagerStopCleanupTestAgent $testAgent,
    ) {
    }

    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        return $this->testAgent;
    }
}

final class WorkerManagerStopCleanupTestAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'unit_stop_cleanup';
    public const string DB_COLLECTION = 'unit_stop_cleanup_db';
    public const string RT_COLLECTION = 'unit_stop_cleanup_rt';

    public bool $sawDbTruthSourceOnStop = false;
    public bool $sawRtTruthSourceOnStop = false;
    public int $handshakeCallCount = 0;
    public ?ValidationException $handshakeException = null;

    public function onStart(): void
    {
        $this->registerDbTruthSource(self::DB_COLLECTION);
        $this->registerRtTruthSource(self::RT_COLLECTION);
    }

    public function onStop(): void
    {
        $this->sawDbTruthSourceOnStop = TruthSourceRegistry::hasTruthSource(self::DB_COLLECTION);
        $this->sawRtTruthSourceOnStop = RtTruthSourceRegistry::hasTruthSource(self::RT_COLLECTION);

        throw new RuntimeException('stop hook failed');
    }

    public function onSignalHandshake(WebSocketHandshakeSignalDTO $data, string $source, string $name): void
    {
        $this->handshakeCallCount++;

        if ($this->handshakeException !== null) {
            throw $this->handshakeException;
        }
    }
}
