<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\ProtectedMode;

use Hilos\Hilos;
use Hilos\ProtectedMode\ClusterProtectedMode;
use Hilos\ProtectedMode\DTO\ProtectedModeDisableSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeEnableSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeQuiesceData;
use Hilos\ProtectedMode\DaemonProtectedModeExecutor;
use Hilos\ProtectedMode\ProtectedModeExecutor;
use Hilos\ProtectedMode\ProtectedModeMesh;
use Hilos\Runtime\Exception\Rt\StateCollectionNotFoundException;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the two-phase cluster freeze orchestration (HIL-267 slice 5).
 *
 * The state machine is driven through the {@see ProtectedModeCoordinator} frame handlers and
 * observed through recording fakes of its two ports, so the leader and follower flows are pinned
 * without a live cluster: a leader collects quiesced reports before signalling ready, a single-node
 * cluster activates at once, a follower freezes and reports back, and the leader role is gated on
 * holding leadership. The wire frames and the daemon wiring are covered by their own slices.
 *
 * Every case runs with the framework-owned freeze row mounted, as a real project boot leaves it;
 * the fail-closed cases unmount it to stand in for a process that carries no runtime state at all.
 */
final class ClusterProtectedModeTest extends TestCase
{
    private const string SELF = 'node-a';

    private FakeProtectedModeMesh $mesh;

    private FakeProtectedModeExecutor $executor;

    private ClusterProtectedMode $coordinator;

    protected function setUp(): void
    {
        $this->mesh = new FakeProtectedModeMesh();
        $this->executor = new FakeProtectedModeExecutor();
        $this->coordinator = new ClusterProtectedMode(self::SELF, $this->mesh, $this->executor);
        $this->mount();
    }

    protected function tearDown(): void
    {
        Hilos::$rt = null;

        parent::tearDown();
    }

    public function testLeaderWithFollowersActivatesOnlyAfterEveryQuiescedReport(): void
    {
        $this->mesh->followers = ['node-b', 'node-c'];
        $this->coordinator->onBecameLeader();

        $this->coordinator->onEnable('node-b', $this->enableData());

        // The leader freezes its own node and broadcasts, but does not activate yet.
        $this->assertSame(['enterActivating'], $this->executor->calls);
        $this->assertSame('accept-9', $this->executor->activatingAcceptKey);
        $this->assertSame([['broadcastQuiesce', 'restore']], $this->mesh->calls);

        $this->coordinator->onQuiesced('node-b');
        $this->assertSame(['enterActivating'], $this->executor->calls);

        $this->coordinator->onQuiesced('node-c');
        $this->assertSame(['enterActivating', 'enterActive'], $this->executor->calls);
        $this->assertSame([['broadcastQuiesce', 'restore'], ['sendReady', 'node-b']], $this->mesh->calls);

        // A late duplicate report does not re-activate.
        $this->coordinator->onQuiesced('node-c');
        $this->assertSame(['enterActivating', 'enterActive'], $this->executor->calls);
    }

    public function testSingleNodeLeaderActivatesImmediately(): void
    {
        $this->mesh->followers = [];
        $this->coordinator->onBecameLeader();

        $this->coordinator->onEnable('node-a', $this->enableData());

        $this->assertSame(['enterActivating', 'enterActive'], $this->executor->calls);
        $this->assertSame([['broadcastQuiesce', 'restore'], ['sendReady', 'node-b']], $this->mesh->calls);
    }

    public function testLeaderDisableDeactivatesBroadcastsLiftAndReleasesSelf(): void
    {
        $this->mesh->followers = [];
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onDisable('node-b');

        $this->assertSame(['enterDeactivating', 'enterInactive'], $this->executor->calls);
        $this->assertSame([['broadcastLift', null]], $this->mesh->calls);
    }

    public function testLeaderIgnoresDisableFromANonInitiatorNode(): void
    {
        $this->mesh->followers = [];
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onDisable('node-c');

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testNonLeaderIgnoresEnable(): void
    {
        $this->mesh->followers = ['node-b'];

        $this->coordinator->onEnable('node-b', $this->enableData());

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testLeaderIgnoresAConcurrentSecondEnable(): void
    {
        $this->mesh->followers = ['node-b'];
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onEnable('node-c', $this->enableData());

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testLeaderTellsItsInitiatorReadyAgainWhenTheFreezeAlreadyStands(): void
    {
        // The cluster half of the operator closing the verification window: every node stays
        // frozen so another restore can run, and that restore's enable must not be refused as a
        // duplicate. The quiesce round is not replayed - the followers never thawed.
        $this->mesh->followers = ['node-b'];
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->coordinator->onQuiesced('node-b');
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onEnable('node-b', $this->enableData());

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([['sendReady', 'node-b']], $this->mesh->calls);
    }

    public function testLeaderStillRefusesASecondNodeUnderAFreezeThatStands(): void
    {
        $this->mesh->followers = ['node-b'];
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->coordinator->onQuiesced('node-b');
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onEnable('node-c', $this->enableDataFrom('node-c'));

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testLeaderRefusesASecondAgentOnTheInitiatorNodeUnderAFreezeThatStands(): void
    {
        // The node id cannot tell apart the two initiators a project holds - the agent that runs
        // real operations and the test driver's carrier share a node - so the identity the freeze
        // records is what refuses this. Answering would send the ready to the recorded agent
        // instead: the one that asked would wait out its timeout, and the other would be told a
        // second time that it may start destroying things.
        $this->mesh->followers = ['node-b'];
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->coordinator->onQuiesced('node-b');
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onEnable('node-b', new ProtectedModeEnableSignalData(
            operation: 'restore',
            initiatorAcceptKey: 'accept-9',
            initiatorAgentType: 'protected-mode-driver',
            initiatorAgentIndex: 0,
            initiatorNodeId: 'node-b',
        ));

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testInitiatorNodeRelaysReadyAgainWhenItsAgentAsksForAnotherFreeze(): void
    {
        // The once-per-freeze guard is re-armed by this node's own request, and it has to be:
        // a freeze that already stands sends no second quiesce, which is the other place the
        // guard is cleared, so the answer to the next operation would be swallowed.
        $this->mesh->leader = 'node-x';
        $this->coordinator->onQuiesce('node-x', new ProtectedModeQuiesceData('restore', 'backup', 0, self::SELF));
        $this->coordinator->onReady('node-x');
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->requestEnable($this->enableDataFrom(self::SELF));
        $this->coordinator->onReady('node-x');

        $this->assertSame(['notifyInitiatorReady'], $this->executor->calls);
        $this->assertSame([['sendEnable', 'node-x']], $this->mesh->calls);
    }

    public function testLostLeadershipDropsInFlightOrchestration(): void
    {
        $this->mesh->followers = ['node-b'];
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onLostLeadership();
        $this->coordinator->onQuiesced('node-b');

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testFollowerFreezesLocallyAndReportsQuiesced(): void
    {
        $freeze = new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b');

        $this->coordinator->onQuiesce('node-x', $freeze);

        $this->assertSame(['enterActivating'], $this->executor->calls);
        $this->assertNull($this->executor->activatingAcceptKey);
        $this->assertSame([['sendQuiesced', 'node-x']], $this->mesh->calls);
    }

    public function testFollowerReleasesOnLift(): void
    {
        $this->coordinator->onQuiesce('node-x', new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b'));
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onLift('node-x');

        $this->assertSame(['enterInactive'], $this->executor->calls);
    }

    public function testFollowerIgnoresLiftWhenNotFrozen(): void
    {
        $this->coordinator->onLift('node-x');

        $this->assertSame([], $this->executor->calls);
    }

    public function testFollowerIgnoresLiftFromANodeOtherThanItsFreezingLeader(): void
    {
        $this->coordinator->onQuiesce('node-x', new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b'));
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onLift('node-y');

        $this->assertSame([], $this->executor->calls);
    }

    public function testFollowerIgnoresARepeatQuiesceWhileAlreadyFrozen(): void
    {
        $freeze = new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b');
        $this->coordinator->onQuiesce('node-x', $freeze);
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onQuiesce('node-x', $freeze);

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testInitiatorNodeRelaysReadyToItsAgentOnceItsFreezingLeaderConfirms(): void
    {
        $this->coordinator->onQuiesce('node-x', new ProtectedModeQuiesceData('restore', 'backup', 0, self::SELF));
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onReady('node-x');

        $this->assertSame(['notifyInitiatorReady'], $this->executor->calls);
    }

    public function testInitiatorNodeIgnoresReadyFromANodeOtherThanItsFreezingLeader(): void
    {
        $this->coordinator->onQuiesce('node-x', new ProtectedModeQuiesceData('restore', 'backup', 0, self::SELF));
        $this->executor->calls = [];

        $this->coordinator->onReady('node-y');

        $this->assertSame([], $this->executor->calls);
    }

    public function testInitiatorNodeRelaysReadyOnlyOnceForRepeatedConfirmations(): void
    {
        $this->coordinator->onQuiesce('node-x', new ProtectedModeQuiesceData('restore', 'backup', 0, self::SELF));
        $this->executor->calls = [];

        $this->coordinator->onReady('node-x');
        $this->coordinator->onReady('node-x');

        $this->assertSame(['notifyInitiatorReady'], $this->executor->calls);
    }

    public function testLeaderInitiatorRelaysReadyLocallyOnceFollowersQuiesce(): void
    {
        $this->mesh->followers = ['node-b'];
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable(self::SELF, $this->enableDataFrom(self::SELF));
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onQuiesced('node-b');

        // The leader is the initiator: the ready is handed to the local agent, never sent to itself.
        $this->assertSame(['enterActive', 'notifyInitiatorReady'], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testInitiatorLeaderHandlesEnableRequestLocally(): void
    {
        $this->mesh->followers = [];
        $this->coordinator->onBecameLeader();

        $this->coordinator->requestEnable($this->enableDataFrom(self::SELF));

        // Routed straight into the leader flow; the leader is the initiator, so the ready is relayed
        // to the local agent instead of being sent over the peer channel to itself.
        $this->assertSame(['enterActivating', 'enterActive', 'notifyInitiatorReady'], $this->executor->calls);
        $this->assertSame([['broadcastQuiesce', 'restore']], $this->mesh->calls);
    }

    public function testInitiatorFollowerSendsEnableRequestToLeader(): void
    {
        $this->mesh->leader = 'node-z';

        $this->coordinator->requestEnable($this->enableDataFrom(self::SELF));

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([['sendEnable', 'node-z']], $this->mesh->calls);
    }

    public function testInitiatorDropsEnableRequestWhenNoLeaderIsKnown(): void
    {
        $this->mesh->leader = null;

        $this->coordinator->requestEnable($this->enableDataFrom(self::SELF));

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testInitiatorLeaderHandlesDisableRequestLocally(): void
    {
        $this->mesh->followers = [];
        $this->coordinator->onBecameLeader();
        $this->coordinator->requestEnable($this->enableDataFrom(self::SELF));
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->requestDisable($this->disableData());

        $this->assertSame(['enterDeactivating', 'enterInactive'], $this->executor->calls);
        $this->assertSame([['broadcastLift', null]], $this->mesh->calls);
    }

    public function testInitiatorFollowerSendsDisableRequestToLeader(): void
    {
        $this->mesh->leader = 'node-z';

        $this->coordinator->requestDisable($this->disableData());

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([['sendDisable', 'node-z']], $this->mesh->calls);
    }

    public function testLeaderDropsEnableThatNamesNoInitiatorNode(): void
    {
        // A payload with no node id is what a single-node installation sends; a leader cannot
        // orchestrate it — it would have nowhere to send ready and nothing to authorize the
        // later disable against — so it refuses instead of entering a freeze it cannot lift.
        $this->mesh->followers = ['node-b'];
        $this->coordinator->onBecameLeader();

        $this->coordinator->onEnable('node-b', $this->enableDataFrom(null));

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testLeaderWithoutRuntimeStateRefusesToEnter(): void
    {
        // Fail-closed: the initiator waits for ready before it destroys anything, so a refusal
        // leaves it waiting safely, where an inert entry would report a freeze that never happened.
        $this->mesh->followers = ['node-b'];
        $this->coordinator->onBecameLeader();
        Hilos::$rt = null;

        $this->coordinator->onEnable('node-b', $this->enableData());

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testARefusedEnterLeavesNoFreezeBehind(): void
    {
        $this->mesh->followers = ['node-b'];
        $this->coordinator->onBecameLeader();
        Hilos::$rt = null;
        $this->coordinator->onEnable('node-b', $this->enableData());

        $this->mount();
        $this->coordinator->onEnable('node-b', $this->enableData());

        // The guard runs above activeFreeze, so the refused attempt left no trace to drop this one
        // against; a leader that recorded the freeze first would reject every later enable forever.
        $this->assertSame(['enterActivating'], $this->executor->calls);
        $this->assertSame([['broadcastQuiesce', 'restore']], $this->mesh->calls);
    }

    public function testFollowerWithoutRuntimeStateRefusesAndReportsNothing(): void
    {
        // Reporting quiesced for a freeze this node never entered would let the leader hand ready
        // to the initiator and run the destructive operation across a node still serving clients.
        Hilos::$rt = null;

        $this->coordinator->onQuiesce('node-x', new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b'));

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
    }

    /**
     * Settles the freeze on the runtime row, standing in for the real executor's write.
     *
     * The fake port records transitions instead of writing any, so a case that turns on the phase
     * the row actually reads has to put it there - through the same item actions
     * {@see DaemonProtectedModeExecutor} uses, with the daemon registered as the runtime truth
     * source for exactly the length of the write and dropped after, because the registration is
     * process-wide.
     */
    private function settleTheFreezeOnTheRuntimeRow(): void
    {
        $view = Hilos::$rt?->hilosProtectedModeRuntime;
        if ($view === null) {
            $this->fail('The protected mode runtime row is not mounted.');
        }

        RtTruthSourceRegistry::registerDaemon(StateProtectedModeRuntime::RT_ITEM);
        try {
            $view->actions->enterActivating(new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b'), null);
            $view->actions->enterActive();
        } finally {
            RtTruthSourceRegistry::unregisterDaemon(StateProtectedModeRuntime::RT_ITEM);
        }
    }

    /**
     * Mounts the framework-owned protected mode runtime row, as a real project boot does.
     *
     * @throws StateCollectionNotFoundException When a feature definition names a collection it did not mount
     */
    private function mount(): void
    {
        Hilos::$rt = new ClusterProtectedModeTestRtContext();
        Hilos::$rt->mountFeatureRuntime([]);
    }

    private function enableData(): ProtectedModeEnableSignalData
    {
        return $this->enableDataFrom('node-b');
    }

    private function disableData(): ProtectedModeDisableSignalData
    {
        return new ProtectedModeDisableSignalData(
            initiatorAgentType: 'backup',
            initiatorAgentIndex: 0,
        );
    }

    private function enableDataFrom(?string $initiatorNodeId): ProtectedModeEnableSignalData
    {
        return new ProtectedModeEnableSignalData(
            operation: 'restore',
            initiatorAcceptKey: 'accept-9',
            initiatorAgentType: 'backup',
            initiatorAgentIndex: 0,
            initiatorNodeId: $initiatorNodeId,
        );
    }
}

/**
 * Project context that registers no runtime state of its own: the framework mount supplies the
 * freeze row.
 *
 * Named apart from its standalone counterpart on purpose - both test files share the namespace, so
 * one context name could not carry two classes and the suite would not load.
 */
final class ClusterProtectedModeTestRtContext extends RtContext
{
    /**
     * Registers no project runtime state: the framework mount supplies the freeze row.
     */
    public function configure(): void
    {
    }
}

/**
 * Recording fake of the outbound peer port: captures each send and the roster it advertises.
 */
final class FakeProtectedModeMesh implements ProtectedModeMesh
{
    /** @var array<string> Follower node ids to advertise to the leader */
    public array $followers = [];

    /** @var ?string Leader node id to advertise to an initiator, or null when leadership is unknown */
    public ?string $leader = null;

    /** @var array<array{0: string, 1: ?string}> Ordered [method, argument] pairs sent */
    public array $calls = [];

    public function followerMasterNodeIds(): array
    {
        return $this->followers;
    }

    public function leaderNodeId(): ?string
    {
        return $this->leader;
    }

    public function sendEnable(string $leaderNodeId, ProtectedModeEnableSignalData $data): void
    {
        $this->calls[] = ['sendEnable', $leaderNodeId];
    }

    public function sendDisable(string $leaderNodeId): void
    {
        $this->calls[] = ['sendDisable', $leaderNodeId];
    }

    public function broadcastQuiesce(ProtectedModeQuiesceData $data): void
    {
        $this->calls[] = ['broadcastQuiesce', $data->operation];
    }

    public function sendReady(string $initiatorNodeId): void
    {
        $this->calls[] = ['sendReady', $initiatorNodeId];
    }

    public function broadcastLift(): void
    {
        $this->calls[] = ['broadcastLift', null];
    }

    public function sendQuiesced(string $leaderNodeId): void
    {
        $this->calls[] = ['sendQuiesced', $leaderNodeId];
    }

    public function sendVerify(string $leaderNodeId): void
    {
        $this->calls[] = ['sendVerify', $leaderNodeId];
    }

    public function broadcastVerify(): void
    {
        $this->calls[] = ['broadcastVerify', null];
    }

    public function sendPass(string $leaderNodeId, string $passHash): void
    {
        $this->calls[] = ['sendPass', $leaderNodeId];
    }

    public function broadcastPass(string $passHash): void
    {
        $this->calls[] = ['broadcastPass', $passHash];
    }

    public function sendRefreeze(string $leaderNodeId): void
    {
        $this->calls[] = ['sendRefreeze', $leaderNodeId];
    }

    public function broadcastRefreeze(): void
    {
        $this->calls[] = ['broadcastRefreeze', null];
    }
}

/**
 * Recording fake of the local-node port: captures the phase transitions the coordinator drives.
 */
final class FakeProtectedModeExecutor implements ProtectedModeExecutor
{
    /** @var array<string> Ordered method names invoked */
    public array $calls = [];

    /** @var ?string Accept key passed to the most recent enterActivating call */
    public ?string $activatingAcceptKey = null;

    public function enterActivating(ProtectedModeQuiesceData $freeze, ?string $initiatorAcceptKey): void
    {
        $this->calls[] = 'enterActivating';
        $this->activatingAcceptKey = $initiatorAcceptKey;
    }

    public function enterActive(): void
    {
        $this->calls[] = 'enterActive';
    }

    public function enterDeactivating(): void
    {
        $this->calls[] = 'enterDeactivating';
    }

    public function enterVerifying(): void
    {
        $this->calls[] = 'enterVerifying';
    }

    public function reenterActive(): void
    {
        $this->calls[] = 'reenterActive';
    }

    public function enterInactive(): void
    {
        $this->calls[] = 'enterInactive';
    }

    public function notifyInitiatorReady(): void
    {
        $this->calls[] = 'notifyInitiatorReady';
    }
}
