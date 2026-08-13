<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\ProtectedMode;

use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Router\SignalRouter;
use Hilos\Hilos;
use Hilos\ProtectedMode\ProtectedModeCommandConstants;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Server\CommandServer;
use Hilos\Socket\Server\WorkerServer;
use JsonException;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * Unit tests for the master-side protected-mode snapshot (HIL-344).
 *
 * The inspector answers in the master because a freeze stops every agent but the initiator,
 * so these cases are about what the master can say on its own: the runtime row's phase and
 * initiator identity, the roster this node stopped, and the "no protected mode here at all"
 * verdict a project without a runtime context must give instead of silence.
 */
final class ProtectedModeSnapshotTest extends TestCase
{
    private const string INITIATOR_TYPE = 'restorer';

    private const string OPERATION = 'restore';

    protected function tearDown(): void
    {
        Hilos::$rt = null;

        parent::tearDown();
    }

    public function testTheSnapshotReportsThePhaseAndInitiatorFromTheRuntimeRow(): void
    {
        $this->freeze(StateProtectedModeRuntime::PHASE_ACTIVE, self::INITIATOR_TYPE, 3);
        $snapshot = $this->buildManager()->protectedModeSnapshot();

        $this->assertTrue($snapshot[ProtectedModeCommandConstants::FIELD_RT_MOUNTED]);
        $this->assertSame(
            StateProtectedModeRuntime::PHASE_ACTIVE,
            $snapshot[ProtectedModeCommandConstants::FIELD_PHASE],
        );
        $this->assertSame(self::OPERATION, $snapshot[ProtectedModeCommandConstants::FIELD_OPERATION]);
        $this->assertSame(
            self::INITIATOR_TYPE,
            $snapshot[ProtectedModeCommandConstants::FIELD_INITIATOR_AGENT_TYPE],
        );
        $this->assertSame(3, $snapshot[ProtectedModeCommandConstants::FIELD_INITIATOR_AGENT_INDEX]);
        $this->assertSame('node-a', $snapshot[ProtectedModeCommandConstants::FIELD_INITIATOR_NODE_ID]);
        $this->assertSame(1000, $snapshot[ProtectedModeCommandConstants::FIELD_STARTED_AT]);
        $this->assertSame(1005, $snapshot[ProtectedModeCommandConstants::FIELD_ACTIVATED_AT]);
    }

    /**
     * @throws JsonException When the snapshot cannot be encoded to JSON
     */
    public function testTheSnapshotNeverPublishesTheInitiatorAcceptKey(): void
    {
        // That key is the pass through the lockdown and the command socket authenticates
        // nobody, so publishing it would hand any reader of the port the one credential the
        // freeze exists to withhold. Asserted against the encoded reply rather than the keys,
        // because what must not leak is the value, wherever it might be nested.
        $this->freeze(StateProtectedModeRuntime::PHASE_ACTIVE, self::INITIATOR_TYPE, null);
        $snapshot = $this->buildManager()->protectedModeSnapshot();

        $this->assertArrayNotHasKey(StateProtectedModeRuntime::initiatorAcceptKey, $snapshot);
        $this->assertStringNotContainsString(
            'secret-accept-key',
            json_encode($snapshot, JSON_THROW_ON_ERROR),
        );
    }

    public function testAProjectWithoutARuntimeContextSaysSoInsteadOfFailing(): void
    {
        // The point of the flag: "the mode is not taken" and "this project has no protected
        // mode at all" are different verdicts, and an assertion cannot tell them apart from an
        // empty reply or an error.
        Hilos::$rt = null;
        $snapshot = $this->buildManager()->protectedModeSnapshot();

        $this->assertFalse($snapshot[ProtectedModeCommandConstants::FIELD_RT_MOUNTED]);
        $this->assertSame(
            StateProtectedModeRuntime::PHASE_INACTIVE,
            $snapshot[ProtectedModeCommandConstants::FIELD_PHASE],
        );
        $this->assertFalse($snapshot[ProtectedModeCommandConstants::FIELD_AGENT_START_GATE_CLOSED]);
    }

    public function testAnUnmountedFreezeRowReadsAsNoProtectedModeHere(): void
    {
        Hilos::$rt = new SnapshotTestRtContext();
        $snapshot = $this->buildManager()->protectedModeSnapshot();

        $this->assertFalse($snapshot[ProtectedModeCommandConstants::FIELD_RT_MOUNTED]);
        $this->assertNull($snapshot[ProtectedModeCommandConstants::FIELD_INITIATOR_AGENT_TYPE]);
    }

    public function testTheSnapshotNamesTheAgentsTheFreezeStoppedOnThisNode(): void
    {
        // The row alone says the mode is on; the roster says the freeze took hold HERE, which
        // is the assertion a test about a frozen node actually wants.
        $this->freeze(StateProtectedModeRuntime::PHASE_ACTIVE, self::INITIATOR_TYPE, null);
        $manager = $this->buildManager(['chat', 'presence:2']);

        $this->assertSame(
            ['chat', 'presence:2'],
            $manager->protectedModeSnapshot()[ProtectedModeCommandConstants::FIELD_STOPPED_AGENTS],
        );
    }

    public function testTheGateFlagFollowsEveryNonInactivePhase(): void
    {
        // Same verdict the agent-start gate reaches: a follower stops at activating and never
        // reaches active, and deactivating is still shut.
        foreach ([
            StateProtectedModeRuntime::PHASE_ACTIVATING,
            StateProtectedModeRuntime::PHASE_ACTIVE,
            StateProtectedModeRuntime::PHASE_DEACTIVATING,
        ] as $phase) {
            $this->freeze($phase, self::INITIATOR_TYPE, null);
            $snapshot = $this->buildManager()->protectedModeSnapshot();

            $this->assertTrue(
                $snapshot[ProtectedModeCommandConstants::FIELD_AGENT_START_GATE_CLOSED],
                "Phase {$phase} must report the agent-start gate as closed.",
            );
        }

        $this->freeze(StateProtectedModeRuntime::PHASE_INACTIVE, null, null);

        $this->assertFalse(
            $this->buildManager()->protectedModeSnapshot()[
                ProtectedModeCommandConstants::FIELD_AGENT_START_GATE_CLOSED
            ],
        );
    }

    public function testTheCommandServerAnswersAnEmptySnapshotUntilTheSourceIsWired(): void
    {
        // A daemon exposing no such seam must read as "nothing to report" rather than fail the
        // command: the absent mounted flag carries the same distinction.
        $server = new ReflectionClass(CommandServer::class)->newInstanceWithoutConstructor();

        $this->assertSame([], $server->protectedModeSnapshot());

        $server->setProtectedModeSnapshotSource($this->buildManager());

        $this->assertArrayHasKey(
            ProtectedModeCommandConstants::FIELD_RT_MOUNTED,
            $server->protectedModeSnapshot(),
        );
    }

    /**
     * Mounts the freeze row in the phase and initiator identity the case needs.
     *
     * Built through the deserialization path an inbound RT sync uses, the same way the gate
     * test mounts it, so a phase with no initiator recorded is reachable at all.
     *
     * @param string $phase Freeze phase to mount
     * @param ?string $initiatorType Initiator agent type recorded on the row
     * @param ?int $initiatorIndex Initiator agent index recorded on the row
     */
    private function freeze(string $phase, ?string $initiatorType, ?int $initiatorIndex): void
    {
        Hilos::$rt = new SnapshotTestRtContext();
        Hilos::$rt->mountFeatureItem(StateProtectedModeRuntime::RT_ITEM, StateProtectedModeRuntime::fromRow([
            StateProtectedModeRuntime::phase => $phase,
            StateProtectedModeRuntime::operation => self::OPERATION,
            StateProtectedModeRuntime::initiatorAcceptKey => 'secret-accept-key',
            StateProtectedModeRuntime::initiatorAgentType => $initiatorType,
            StateProtectedModeRuntime::initiatorAgentIndex => $initiatorIndex,
            StateProtectedModeRuntime::initiatorNodeId => 'node-a',
            StateProtectedModeRuntime::startedAt => 1000,
            StateProtectedModeRuntime::activatedAt => 1005,
        ]));
    }

    /**
     * Builds a daemon manager holding a worker server that reports the given stopped roster.
     *
     * Skips the constructor: it builds an event loop, servers and a signal router that the
     * snapshot never touches - the snapshot reads the runtime row and one server.
     *
     * @param list<string> $stoppedAgents Agent ids the freeze stopped on this node
     * @return SnapshotTestDaemonManager Manager under test
     */
    private function buildManager(array $stoppedAgents = []): SnapshotTestDaemonManager
    {
        $manager = new ReflectionClass(SnapshotTestDaemonManager::class)->newInstanceWithoutConstructor();
        $workerServer = new ReflectionClass(SnapshotTestWorkerServer::class)->newInstanceWithoutConstructor();
        $workerServer->stoppedAgents = $stoppedAgents;

        new ReflectionProperty(DaemonManager::class, 'servers')->setValue($manager, [$workerServer]);

        return $manager;
    }
}

/**
 * Daemon manager reduced to the two factories the base class demands; neither is called here.
 */
final class SnapshotTestDaemonManager extends DaemonManager
{
    protected function createSignalRouter(): SignalRouter
    {
        throw new LogicException('The snapshot never builds a signal router.');
    }

    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        throw new LogicException('The snapshot never builds an agent manager.');
    }
}

/**
 * Worker server that answers a fixed stopped roster, standing in for a live freeze.
 */
final class SnapshotTestWorkerServer extends WorkerServer
{
    /** @var list<string> Agent ids to report as stopped for the freeze */
    public array $stoppedAgents = [];

    public function getProtectedModeStoppedAgents(): array
    {
        return $this->stoppedAgents;
    }

    protected function onStart(): void
    {
        // Not used in this test
    }
}

/**
 * Runtime context that registers no project state: the framework mount supplies the freeze row.
 */
final class SnapshotTestRtContext extends RtContext
{
    public function configure(): void
    {
    }
}
