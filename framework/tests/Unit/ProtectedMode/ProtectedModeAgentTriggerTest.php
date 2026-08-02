<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\ProtectedMode;

use Hilos\Cluster\ClusterContext;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Router\SignalRouter;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\ProtectedMode\DTO\ProtectedModeDisableSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeEnableSignalData;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the initiator agent trigger (HIL-267 slice 5e).
 *
 * The initiator agent runs in a worker and cannot emit a peer frame, so it queues a worker-drained
 * protected-mode signal that its own daemon later turns into a request frame. These tests pin the
 * agent-facing half: {@see AbstractAgent::requestProtectedModeEnable()} queues the enable signal with
 * this node's initiator identity, {@see AbstractAgent::requestProtectedModeDisable()} queues the empty
 * disable signal, and both are silent no-ops when cluster mode is off. The worker-to-daemon frame
 * emission lives on {@see \Hilos\Core\Daemon\WorkerManager} and is exercised end-to-end.
 */
final class ProtectedModeAgentTriggerTest extends TestCase
{
    /** @var ?EnvAccessor Env accessor to restore after the test */
    private ?EnvAccessor $previousEnv = null;

    /** @var ?ClusterContext Cluster context to restore after the test */
    private ?ClusterContext $previousCluster = null;

    protected function setUp(): void
    {
        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        $this->previousCluster = Hilos::$cluster;
        Hilos::$sr = new SignalRouter();
    }

    protected function tearDown(): void
    {
        Hilos::$sr = null;
        Hilos::$env = $this->previousEnv;
        Hilos::$cluster = $this->previousCluster;
        putenv('CLUSTER_ENABLED');
        putenv('CLUSTER_NODE_ID');
        putenv('CLUSTER_NODE_ROLE');

        parent::tearDown();
    }

    /**
     * Enables cluster mode with a resolvable local node identity.
     */
    private function enableCluster(): void
    {
        putenv('CLUSTER_ENABLED=true');
        putenv('CLUSTER_NODE_ID=node-a');
        putenv('CLUSTER_NODE_ROLE=master');
        Hilos::$env = new EnvAccessor();
        Hilos::$cluster = new ClusterContext();
    }

    public function testEnableQueuesSignalCarryingThisNodesInitiatorIdentity(): void
    {
        $this->enableCluster();

        new ProtectedModeTriggerTestAgent('7')->enable('restore', 'accept-key-1');

        $signal = Hilos::$sr->getNextQueuedSignal();
        $this->assertNotNull($signal, 'Enable queues one worker-drained signal');
        $this->assertSame(SignalTypeConstants::PROTECTED_MODE_ENABLE, $signal->signalType->getType());
        $this->assertInstanceOf(ProtectedModeEnableSignalData::class, $signal->data);
        $this->assertSame('restore', $signal->data->operation);
        $this->assertSame('accept-key-1', $signal->data->initiatorAcceptKey);
        $this->assertSame('restore-initiator', $signal->data->initiatorAgentType);
        $this->assertSame(7, $signal->data->initiatorAgentIndex, 'Agent index is carried as an int');
        $this->assertSame('node-a', $signal->data->initiatorNodeId, 'The freeze names this node as initiator');
        $this->assertNull(Hilos::$sr->getNextQueuedSignal(), 'Exactly one signal is queued');
    }

    public function testEnableFromSingletonAgentCarriesNullIndex(): void
    {
        $this->enableCluster();

        new ProtectedModeTriggerTestAgent(null)->enable('restore', 'accept-key-2');

        $signal = Hilos::$sr->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertInstanceOf(ProtectedModeEnableSignalData::class, $signal->data);
        $this->assertNull($signal->data->initiatorAgentIndex, 'A singleton initiator carries a null index');
    }

    public function testDisableQueuesEmptyDisableSignal(): void
    {
        $this->enableCluster();

        new ProtectedModeTriggerTestAgent('7')->disable();

        $signal = Hilos::$sr->getNextQueuedSignal();
        $this->assertNotNull($signal, 'Disable queues one worker-drained signal');
        $this->assertSame(SignalTypeConstants::PROTECTED_MODE_DISABLE, $signal->signalType->getType());
        $this->assertInstanceOf(ProtectedModeDisableSignalData::class, $signal->data);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal(), 'Exactly one signal is queued');
    }

    public function testEnableIsSilentWhenClusterModeIsOff(): void
    {
        Hilos::$env = new EnvAccessor();
        Hilos::$cluster = new ClusterContext();

        new ProtectedModeTriggerTestAgent('7')->enable('restore', 'accept-key-3');

        $this->assertNull(Hilos::$sr->getNextQueuedSignal(), 'A non-clustered node has no cluster to freeze');
    }

    public function testDisableIsSilentWhenClusterModeIsOff(): void
    {
        Hilos::$env = new EnvAccessor();
        Hilos::$cluster = new ClusterContext();

        new ProtectedModeTriggerTestAgent('7')->disable();

        $this->assertNull(Hilos::$sr->getNextQueuedSignal(), 'A non-clustered node has nothing to release');
    }
}

/**
 * A minimal initiator agent that exposes the protected protected-mode triggers for the test.
 */
final class ProtectedModeTriggerTestAgent extends AbstractAgent
{
    /** @var string Agent type identifier */
    public const string AGENT_TYPE = 'restore-initiator';

    /**
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     */
    public function __construct(?string $agentIndex = null)
    {
        $this->agentIndex = $agentIndex;
    }

    public function onStop(): void
    {
    }

    /**
     * @param string $operation Operation the freeze protects
     * @param string $initiatorAcceptKey Accept key of the driving connection
     */
    public function enable(string $operation, string $initiatorAcceptKey): void
    {
        $this->requestProtectedModeEnable($operation, $initiatorAcceptKey);
    }

    public function disable(): void
    {
        $this->requestProtectedModeDisable();
    }
}
