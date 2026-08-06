<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\DTO\AgentMessageDTOInterface;
use Hilos\Core\Agent\Exception\NoSuitableWorkerException;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * Unit tests for the protected-mode freeze gate in WorkerServer (HIL-522).
 *
 * The freeze stops this node's agents, and before the gate any inbound signal started them
 * straight back up mid-restore. The gate refuses every start but the initiator's for as long
 * as the row is not inactive, and refuses quietly: a signal to a frozen agent is dropped
 * rather than turned into an exception dispatchSignals() does not catch.
 */
final class WorkerServerProtectedModeGateTest extends TestCase
{
    private const string INITIATOR_TYPE = 'restorer';

    private const string OTHER_TYPE = 'chat';

    private const string PER_NODE_TYPE = 'presence';

    /** @var class-string<Hilos> App class bound before this test touched it */
    private string $boundAppClass;

    protected function setUp(): void
    {
        $this->boundAppClass = Hilos::appClass();
    }

    protected function tearDown(): void
    {
        $this->bindAppClass($this->boundAppClass);
        Hilos::$rt = null;

        parent::tearDown();
    }

    public function testAFrozenNodeRefusesAStartFromAnyoneButTheInitiator(): void
    {
        $manager = new FreezeGateTestAgentManagerDaemon();
        $server = $this->buildServer(FreezeGateTestWorkerServer::class, $manager);
        $this->freeze(StateProtectedModeRuntime::PHASE_ACTIVE, self::INITIATOR_TYPE, null);

        // No exception: the gate returns before worker selection.
        $server->startAgentPublic(self::OTHER_TYPE);

        $this->assertFalse(
            $manager->hasAgent(self::OTHER_TYPE),
            'A refused start must leave no agent record behind.',
        );
    }

    public function testTheInitiatorAgentStillStartsDuringTheFreeze(): void
    {
        $server = $this->buildServer(FreezeGateTestWorkerServer::class, new FreezeGateTestAgentManagerDaemon());
        $this->freeze(StateProtectedModeRuntime::PHASE_ACTIVE, self::INITIATOR_TYPE, 7);

        // Passing the gate reaches worker selection, which has no workers registered.
        $this->expectException(NoSuitableWorkerException::class);
        $server->startAgentPublic(self::INITIATOR_TYPE, '7');
    }

    public function testTheGateIsTransparentWhileTheModeIsInactive(): void
    {
        $server = $this->buildServer(FreezeGateTestWorkerServer::class, new FreezeGateTestAgentManagerDaemon());
        $this->freeze(StateProtectedModeRuntime::PHASE_INACTIVE, null, null);

        $this->expectException(NoSuitableWorkerException::class);
        $server->startAgentPublic(self::OTHER_TYPE);
    }

    public function testTheGateIsTransparentWithoutARuntimeContext(): void
    {
        $server = $this->buildServer(FreezeGateTestWorkerServer::class, new FreezeGateTestAgentManagerDaemon());
        Hilos::$rt = null;

        // A project with no runtime context cannot enter the mode, so there is no freeze to hold.
        $this->expectException(NoSuitableWorkerException::class);
        $server->startAgentPublic(self::OTHER_TYPE);
    }

    public function testTheGateIsTransparentWhenTheFreezeRowIsNotMounted(): void
    {
        $server = $this->buildServer(FreezeGateTestWorkerServer::class, new FreezeGateTestAgentManagerDaemon());
        Hilos::$rt = new FreezeGateTestRtContext();

        $this->expectException(NoSuitableWorkerException::class);
        $server->startAgentPublic(self::OTHER_TYPE);
    }

    public function testAFreezeWithNoRecordedInitiatorRefusesEveryone(): void
    {
        $manager = new FreezeGateTestAgentManagerDaemon();
        $server = $this->buildServer(FreezeGateTestWorkerServer::class, $manager);
        $this->freeze(StateProtectedModeRuntime::PHASE_ACTIVE, null, null);

        // An unknown initiator must read as "nobody may start", never as "everybody may".
        $server->startAgentPublic(self::INITIATOR_TYPE);

        $this->assertFalse($manager->hasAgent(self::INITIATOR_TYPE));
    }

    public function testTheGateHoldsInActivatingAndInDeactivating(): void
    {
        // A follower quiesces to activating and never reaches active; deactivating is the
        // wind-down, where pages are still shut. A gate open in either reopens this defect.
        foreach ([StateProtectedModeRuntime::PHASE_ACTIVATING, StateProtectedModeRuntime::PHASE_DEACTIVATING] as $phase) {
            $manager = new FreezeGateTestAgentManagerDaemon();
            $server = $this->buildServer(FreezeGateTestWorkerServer::class, $manager);
            $this->freeze($phase, self::INITIATOR_TYPE, null);

            $server->startAgentPublic(self::OTHER_TYPE);

            $this->assertFalse($manager->hasAgent(self::OTHER_TYPE), "Phase {$phase} must refuse a non-initiator start.");
        }
    }

    public function testASignalToAFrozenAgentIsDroppedInsteadOfThrowing(): void
    {
        $manager = new FreezeGateTestAgentManagerDaemon();
        $server = $this->buildServer(FreezeGateTestWorkerServer::class, $manager);
        $this->freeze(StateProtectedModeRuntime::PHASE_ACTIVE, self::INITIATOR_TYPE, null);

        // Without the check in sendSignalToAgent() the gate would surface as
        // AgentNotFoundException, which dispatchSignals() does not catch - killing the daemon
        // in the middle of the restore the freeze exists to protect.
        $server->sendSignalToAgent(self::OTHER_TYPE, null, new DaemonAgentMessageDTO(self::OTHER_TYPE, $this->noopSignal()));

        $this->assertFalse($manager->hasAgent(self::OTHER_TYPE));
    }

    public function testResumeBringsTheStoppedRosterBackAndFiresTheLiftHook(): void
    {
        $manager = new FreezeGateTestAgentManagerDaemon();
        $server = $this->buildServer(FreezeGateTestLiftRecordingWorkerServer::class, $manager);
        $this->startAndLeaveUnlinked($server, self::OTHER_TYPE, null);

        $this->freeze(StateProtectedModeRuntime::PHASE_ACTIVE, self::INITIATOR_TYPE, null);
        $server->stopAgentsForProtectedMode(self::INITIATOR_TYPE, null);
        $this->clearRoster($manager);

        // The executor writes the inactive phase before it resumes, so the replayed starts
        // pass the gate on their own.
        $this->freeze(StateProtectedModeRuntime::PHASE_INACTIVE, null, null);
        $server->resumeAgentsForProtectedMode();

        $this->assertTrue($manager->hasAgent(self::OTHER_TYPE), 'The remembered agent must come back on lift.');
        $this->assertSame(1, $server->liftHookCalls, 'The lift hook must fire once, after the roster is replayed.');
    }

    public function testStoppingForTheFreezeSkipsAnIndexedInitiator(): void
    {
        $manager = new FreezeGateTestAgentManagerDaemon();
        $server = $this->buildServer(FreezeGateTestLiftRecordingWorkerServer::class, $manager);
        $this->startAndLeaveUnlinked($server, self::OTHER_TYPE, null);
        $this->startAndLeaveUnlinked($server, self::INITIATOR_TYPE, '3');

        // A non-null initiator index used to hit a TypeError under strict_types: the port
        // declared ?string where buildAgentId() takes string.
        $this->freeze(StateProtectedModeRuntime::PHASE_ACTIVE, self::INITIATOR_TYPE, 3);
        $server->stopAgentsForProtectedMode(self::INITIATOR_TYPE, '3');
        $this->clearRoster($manager);

        $this->freeze(StateProtectedModeRuntime::PHASE_INACTIVE, null, null);
        $server->resumeAgentsForProtectedMode();

        $this->assertTrue($manager->hasAgent(self::OTHER_TYPE), 'Every agent but the initiator is stopped and replayed.');
        $this->assertFalse(
            $manager->hasAgent(self::INITIATOR_TYPE . ':3'),
            'The initiator kept running through the freeze, so it is in no remembered roster.',
        );
    }

    public function testTheDefaultLiftHookStartsThePerNodeAgents(): void
    {
        $manager = new FreezeGateTestAgentManagerDaemon();
        $server = $this->buildServer(FreezeGateTestWorkerServer::class, $manager);
        $this->bindAppClass(FreezeGateTestHilos::class);
        $this->freeze(StateProtectedModeRuntime::PHASE_INACTIVE, null, null);

        // The roster is captured once on entry, so an agent the gate refused mid-freeze is in
        // no list; the per-node registry is what the framework can bring back on its own.
        $server->liftPublic();

        $this->assertTrue($manager->hasAgent(self::PER_NODE_TYPE));
    }

    /**
     * Mounts the freeze row in the phase and initiator identity the case needs.
     *
     * Built through the deserialization path an inbound RT sync uses, which is the only way
     * to reach a live phase with no initiator recorded - the write actions always record one.
     *
     * @param string $phase Freeze phase to mount
     * @param ?string $initiatorType Initiator agent type recorded on the row
     * @param ?int $initiatorIndex Initiator agent index recorded on the row
     */
    private function freeze(string $phase, ?string $initiatorType, ?int $initiatorIndex): void
    {
        Hilos::$rt = new FreezeGateTestRtContext();
        Hilos::$rt->mountFeatureItem(StateProtectedModeRuntime::RT_ITEM, StateProtectedModeRuntime::fromRow([
            StateProtectedModeRuntime::phase => $phase,
            StateProtectedModeRuntime::initiatorAgentType => $initiatorType,
            StateProtectedModeRuntime::initiatorAgentIndex => $initiatorIndex,
        ]));
    }

    /**
     * Registers an agent on the node the way a start with no worker registered leaves it.
     *
     * @param FreezeGateTestWorkerServer $server Server under test
     * @param string $agentType Agent type to register
     * @param ?string $agentIndex Agent index to register
     */
    private function startAndLeaveUnlinked(FreezeGateTestWorkerServer $server, string $agentType, ?string $agentIndex): void
    {
        try {
            $server->startAgentPublic($agentType, $agentIndex);
            $this->fail('Worker selection was expected to fail with no workers registered.');
        } catch (NoSuitableWorkerException) {
            // Expected: the agent record survives, which is what the roster is read from.
        }
    }

    /**
     * Empties the node's roster, standing in for the stop a live worker link completes.
     *
     * {@see WorkerServer::stopAgent()} only removes the record once it has told the hosting
     * worker, and no worker is registered here - so what the freeze remembered would be
     * indistinguishable from what it never stopped.
     *
     * @param FreezeGateTestAgentManagerDaemon $manager Agent manager to empty
     */
    private function clearRoster(FreezeGateTestAgentManagerDaemon $manager): void
    {
        foreach (array_keys($manager->getAgents()) as $agentId) {
            $manager->removeAgent($agentId);
        }
    }

    /**
     * @param class-string<FreezeGateTestWorkerServer> $serverClass Server class to instantiate
     * @param AgentManagerDaemon $manager Agent manager the server routes starts through
     * @return FreezeGateTestWorkerServer Server holding that manager
     */
    private function buildServer(string $serverClass, AgentManagerDaemon $manager): FreezeGateTestWorkerServer
    {
        // Skip the constructor: it reads worker env and creates a log directory that this gate
        // test does not exercise. Only the agent manager is needed.
        $server = new ReflectionClass($serverClass)->newInstanceWithoutConstructor();

        new ReflectionProperty(WorkerServer::class, 'agentManager')->setValue($server, $manager);

        return $server;
    }

    /**
     * @param class-string<Hilos> $hilosClass Project facade class the per-node registry is read from
     */
    private function bindAppClass(string $hilosClass): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $hilosClass);
    }

    /**
     * @return SignalDTO Benign signal that carries nothing the delivery path reads
     */
    private function noopSignal(): SignalDTO
    {
        return new SignalDTO(
            new SignalSource(SignalSource::DAEMON),
            new SignalType('noop'),
            new SignalName('noop'),
            new SignalData([]),
        );
    }
}

/**
 * Worker server that exposes the protected start and lift paths for the gate test.
 */
class FreezeGateTestWorkerServer extends WorkerServer
{
    public function startAgentPublic(string $agentType, ?string $agentIndex = null): void
    {
        $this->startAgent($agentType, $agentIndex);
    }

    public function liftPublic(): void
    {
        $this->onProtectedModeLifted();
    }

    protected function onStart(): void
    {
        // Not used in this test
    }
}

/**
 * Worker server whose lift hook records instead of starting anything, so the resume can be
 * observed apart from the hook's own default.
 */
final class FreezeGateTestLiftRecordingWorkerServer extends FreezeGateTestWorkerServer
{
    /** @var int Times the lift hook fired */
    public int $liftHookCalls = 0;

    protected function onProtectedModeLifted(): void
    {
        $this->liftHookCalls++;
    }
}

/**
 * Agent manager whose factory answers for every type the gate test starts.
 */
final class FreezeGateTestAgentManagerDaemon extends AgentManagerDaemon
{
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        return new FreezeGateTestAgentDaemon($agentIndex);
    }
}

/**
 * Agent daemon that requires nothing, so only the freeze gate decides its fate.
 */
final class FreezeGateTestAgentDaemon extends AbstractAgentDaemon
{
    public function __construct(?string $agentIndex)
    {
        $this->agentIndex = $agentIndex;
    }

    public function requiresMonopolisticProcess(): bool
    {
        return false;
    }

    public function requiresClusterLeadership(): bool
    {
        return false;
    }

    public function sendToUser(AgentMessageDTOInterface $message): void
    {
        // Not used in this test
    }
}

/**
 * Runtime context that registers no project state: the framework mount supplies the freeze row.
 */
final class FreezeGateTestRtContext extends RtContext
{
    public function configure(): void
    {
    }
}

/**
 * Project facade whose registry declares one per-node agent, for the default lift hook.
 *
 * Abstract because only its registry constant is read: the hook never builds a database.
 */
abstract class FreezeGateTestHilos extends Hilos
{
    public const array AGENTS = [
        'presence' => [AgentRegistryKey::PER_NODE => true],
    ];
}
