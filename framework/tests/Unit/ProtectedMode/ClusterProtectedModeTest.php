<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\ProtectedMode;

use Closure;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Placement\AgentLocation;
use Hilos\Hilos;
use Hilos\ProtectedMode\ClusterProtectedMode;
use Hilos\ProtectedMode\DTO\ProtectedModeCircleSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeDisableSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeEnableSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeProgressSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeQuiesceData;
use Hilos\ProtectedMode\DaemonProtectedModeExecutor;
use Hilos\ProtectedMode\ProtectedModeExecutor;
use Hilos\ProtectedMode\ProtectedModeInitiatorRelay;
use Hilos\ProtectedMode\ProtectedModeMesh;
use Hilos\ProtectedMode\ProtectedModeRefusalCopy;
use Hilos\ProtectedMode\VerifierCircleSnapshot;
use Hilos\Runtime\Exception\Rt\StateCollectionNotFoundException;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;
use Hilos\ProtectedMode\ProtectedModeCoordinator;

/**
 * Unit tests for the two-phase cluster freeze orchestration (HIL-267 slice 5).
 *
 * The state machine is driven through the {@see ProtectedModeCoordinator} frame handlers and
 * observed through recording fakes of its two ports, so the leader and follower flows are pinned
 * without a live cluster: a leader collects quiesced reports - its own roster's among them - before
 * signalling ready, a single-node cluster activates once its roster has stopped, a follower freezes
 * and reports back when its roster has, and the leader role is gated on holding leadership. The
 * wire frames and the daemon wiring are covered by their own slices.
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

    private FakeClusterInitiatorRelay $relay;

    protected function setUp(): void
    {
        $this->mesh = new FakeProtectedModeMesh();
        $this->executor = new FakeProtectedModeExecutor();
        $this->coordinator = new ClusterProtectedMode(self::SELF, $this->mesh, $this->executor);
        $this->relay = new FakeClusterInitiatorRelay();
        Hilos::$cluster = new ClusterContext();
        Hilos::$cluster->registerProtectedModeInitiatorRelay($this->relay);
        $this->mount();
    }

    protected function tearDown(): void
    {
        Hilos::$cluster = null;
        Hilos::$rt = null;

        parent::tearDown();
    }

    public function testLeaderWithFollowersActivatesOnlyAfterEveryQuiescedReport(): void
    {
        $this->mesh->followers = ['node-b', 'node-c'];
        $this->coordinator->onBecameLeader();

        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->stopTheRoster();

        // The leader freezes its own node and broadcasts, but does not activate yet.
        $this->assertSame(['enterActivating'], $this->executor->calls);
        $this->assertSame('accept-9', $this->executor->activatingAcceptKey);
        $this->assertSame([['broadcastQuiesce', 'restore']], $this->mesh->calls);

        $this->coordinator->onQuiesced('node-b');
        $this->assertSame(['enterActivating'], $this->executor->calls);

        $this->coordinator->onQuiesced('node-c');
        $this->assertSame(['enterActivating', 'enterActive'], $this->executor->calls);
        $this->assertSame([['broadcastQuiesce', 'restore'], ['broadcastSettled', null], ['sendReady', 'node-b']], $this->mesh->calls);

        // A late duplicate report does not re-activate.
        $this->coordinator->onQuiesced('node-c');
        $this->assertSame(['enterActivating', 'enterActive'], $this->executor->calls);
    }

    public function testSingleNodeLeaderActivatesOnceItsOwnRosterHasStopped(): void
    {
        $this->mesh->followers = [];
        $this->coordinator->onBecameLeader();

        $this->coordinator->onEnable('node-a', $this->enableData());

        $this->assertSame(['enterActivating'], $this->executor->calls);
        $this->assertSame([self::SELF], $this->coordinator->pendingNodeIds());

        $this->stopTheRoster();

        $this->assertSame(['enterActivating', 'enterActive'], $this->executor->calls);
        $this->assertSame([['broadcastQuiesce', 'restore'], ['broadcastSettled', null], ['sendReady', 'node-b']], $this->mesh->calls);
        $this->assertSame([], $this->coordinator->pendingNodeIds());
    }

    public function testLeaderWaitsForItsOwnRosterEvenOnceEveryFollowerHasReported(): void
    {
        // A follower with a shorter roster reports before the leader's own has stopped. Counting
        // followers only, that report would hand ready to the initiator while the leader's node
        // was still serving its clients (HIL-1012).
        $this->mesh->followers = ['node-b'];
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->enableData());

        $this->coordinator->onQuiesced('node-b');

        $this->assertSame(['enterActivating'], $this->executor->calls);
        $this->assertSame([self::SELF], $this->coordinator->pendingNodeIds());

        $this->stopTheRoster();

        $this->assertSame(['enterActivating', 'enterActive'], $this->executor->calls);
        $this->assertSame([['broadcastQuiesce', 'restore'], ['broadcastSettled', null], ['sendReady', 'node-b']], $this->mesh->calls);
    }

    public function testTheRosterStoppedByClosingTheWindowBackAnswersNobody(): void
    {
        // The close is the entry's quiesce round (HIL-1128): every node stops its roster again and
        // the leader writes active only once all have reported. Its initiator was told ready when
        // the freeze first took hold, so the round tells the followers and nobody else.
        $this->closeTheWindowBackOnTheLeader(acceptKey: 'accept-on-row', sessionTokenHash: 'session-on-row');

        $this->assertSame(['enterActivating'], $this->executor->calls);
        $this->assertSame('accept-on-row', $this->executor->activatingAcceptKey);
        $this->assertSame('session-on-row', $this->executor->activatingSessionTokenHash);
        $this->assertSame([['broadcastQuiesce', 'restore']], $this->mesh->calls);
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->stopTheRoster();
        $this->coordinator->onQuiesced('node-b');

        $this->assertSame(['enterActive'], $this->executor->calls);
        $this->assertSame([['broadcastSettled', null]], $this->mesh->calls);
    }

    public function testLeaderClosingWaitsForItsOwnRoster(): void
    {
        $this->closeTheWindowBackOnTheLeader();
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onQuiesced('node-b');

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);

        $this->stopTheRoster();

        $this->assertSame(['enterActive'], $this->executor->calls);
        $this->assertSame([['broadcastSettled', null]], $this->mesh->calls);
    }

    public function testAnEnableDuringTheCloseRoundIsRefusedAsAnotherOperation(): void
    {
        // Not a ready over agents still running on some node (HIL-1128): the round is open, as it
        // is during the first entry.
        $this->closeTheWindowBackOnTheLeader();
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onEnable('node-b', $this->enableData());

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([
            ['sendRefused', 'node-b', ProtectedModeRefusalCopy::ANOTHER_OPERATION],
        ], $this->mesh->calls);
    }

    public function testAnEnableAfterTheCloseRoundIsToldReady(): void
    {
        $this->closeTheWindowBackOnTheLeader();
        $this->stopTheRoster();
        $this->coordinator->onQuiesced('node-b');
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onEnable('node-b', $this->enableData());

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([['sendReady', 'node-b']], $this->mesh->calls);
    }

    public function testAFollowerIgnoresARefreezeFromItsLeader(): void
    {
        // The leader closes the window with its quiesce round, and sends no follower this frame.
        $this->coordinator->onQuiesce('node-x', new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', null));
        $this->stopTheRoster();
        $this->openTheVerificationWindowOnTheRuntimeRow();
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onRefreeze('node-x', 'backup', 0);

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testAFollowerWritesActiveOnItsLeadersSettled(): void
    {
        $this->coordinator->onQuiesce('node-x', new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', null));
        $this->stopTheRoster();
        $this->executor->calls = [];

        $this->coordinator->onSettled('node-x');

        $this->assertSame(['enterActive'], $this->executor->calls);
    }

    public function testAFollowerIgnoresASettledFromAnotherNode(): void
    {
        $this->coordinator->onQuiesce('node-x', new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', null));
        $this->stopTheRoster();
        $this->executor->calls = [];

        $this->coordinator->onSettled('node-y');

        $this->assertSame([], $this->executor->calls);
    }

    public function testAFollowerIgnoresASettledOffActivating(): void
    {
        // The frame rides every link to the node: a copy over the second link arrives on active,
        // with the circle already on the row, and writing active again would clear it.
        $this->coordinator->onQuiesce('node-x', new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', null));
        $this->stopTheRoster();
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->executor->calls = [];

        $this->coordinator->onSettled('node-x');

        $this->assertSame([], $this->executor->calls);

        $this->openTheVerificationWindowOnTheRuntimeRow();

        $this->coordinator->onSettled('node-x');

        $this->assertSame([], $this->executor->calls);
    }

    public function testTheRosterBackFinishesWhicheverLiftTheRowSays(): void
    {
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->openTheVerificationWindowOnTheRuntimeRow();

        $this->coordinator->onRosterResumed();
        $this->assertSame(['finishVerifying'], $this->executor->calls);

        $this->withDaemonTruthSource(static fn() => Hilos::$rt?->hilosProtectedModeRuntime?->actions->enterInactive());
        $this->executor->calls = [];

        $this->coordinator->onRosterResumed();
        $this->assertSame(['finishLift'], $this->executor->calls);
    }

    public function testLeaderDisableDeactivatesBroadcastsLiftAndReleasesSelf(): void
    {
        $this->mesh->followers = [];
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onDisable('node-b', 'backup', 0);

        $this->assertSame(['enterDeactivating', 'enterInactive'], $this->executor->calls);
        $this->assertSame([['broadcastLift', null]], $this->mesh->calls);
    }

    public function testLeaderIgnoresDisableFromAnotherAgent(): void
    {
        $this->mesh->followers = [];
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onDisable('node-c', 'other-agent', 0);

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testMovedInitiatorCanDriveTheFreezeByIdentity(): void
    {
        $this->mesh->followers = [];
        $this->mesh->location = AgentLocation::onNode('node-c');
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->stopTheRoster();
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->mesh->calls = [];

        $this->coordinator->onVerify('node-c', 'backup', 0);
        $this->openTheVerificationWindowOnTheRuntimeRow();
        $this->withDaemonTruthSource(function (): void {
            $this->coordinator->onPass('node-c', 'backup', 0, 'pass-hash');
            $this->coordinator->onCircle('node-c', 'backup', 0, $this->circleSnapshot());
        });
        $this->coordinator->onRefreeze('node-c', 'backup', 0);

        $this->assertSame([
            ['broadcastVerify', null],
            ['broadcastPass', 'pass-hash'],
            ['broadcastCircle', '1'],
            ['broadcastQuiesce', 'restore'],
        ], $this->mesh->calls);

        $this->coordinator->onDisable('node-c', 'backup', 0);
        $this->assertContains('enterInactive', $this->executor->calls);
    }

    public function testLeaderRejectsOtherAgentTypesAndIndexesOnEveryInitiatorFrame(): void
    {
        $this->mesh->followers = [];
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->settleTheFreezeOnTheRuntimeRow();

        foreach ([['other-agent', 0], ['backup', 1]] as [$agentType, $agentIndex]) {
            $this->executor->calls = [];
            $this->mesh->calls = [];

            $this->coordinator->onVerify('node-c', $agentType, $agentIndex);
            $this->coordinator->onProgress('node-c', $agentType, $agentIndex);
            $this->coordinator->onCircle('node-c', $agentType, $agentIndex, $this->circleSnapshot());
            $this->coordinator->onDisable('node-c', $agentType, $agentIndex);
            $this->openTheVerificationWindowOnTheRuntimeRow();
            $this->coordinator->onPass('node-c', $agentType, $agentIndex, 'pass-hash');
            $this->coordinator->onRefreeze('node-c', $agentType, $agentIndex);

            $this->assertSame([], $this->executor->calls);
            $this->assertSame([], $this->mesh->calls);
            $this->assertSame([], Hilos::$rt?->hilosProtectedModeRuntime?->passHashes);
            $this->assertSame([], Hilos::$rt?->hilosProtectedModeRuntime?->circleSessionTokenHashes);
        }
    }

    public function testReadyUsesCurrentAgentPlacementAndDropsUnknown(): void
    {
        $this->mesh->followers = [];
        $this->mesh->location = AgentLocation::unknown();
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->stopTheRoster();

        $this->assertSame([['broadcastQuiesce', 'restore'], ['broadcastSettled', null]], $this->mesh->calls);
        $this->assertNotContains('notifyInitiatorReady', $this->executor->calls);

        $this->mesh->location = AgentLocation::onNode('node-c');
        $this->mesh->calls = [];
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->coordinator->onEnable('node-c', $this->enableDataFrom('node-c'));

        $this->assertSame([['sendReady', 'node-c']], $this->mesh->calls);
    }

    public function testNonLeaderIgnoresEnable(): void
    {
        $this->mesh->followers = ['node-b'];

        $this->coordinator->onEnable('node-b', $this->enableData());

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([
            ['sendRefused', 'node-b', ProtectedModeRefusalCopy::NO_LEADER],
        ], $this->mesh->calls);
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
        $this->assertSame([
            ['sendRefused', 'node-c', ProtectedModeRefusalCopy::ANOTHER_OPERATION],
        ], $this->mesh->calls);
    }

    public function testLeaderTellsItsInitiatorReadyAgainWhenTheFreezeAlreadyStands(): void
    {
        // The cluster half of the operator closing the verification window: every node stays
        // frozen so another restore can run, and that restore's enable must not be refused as a
        // duplicate. The quiesce round is not replayed - the followers never thawed.
        $this->mesh->followers = ['node-b'];
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->stopTheRoster();
        $this->coordinator->onQuiesced('node-b');
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onEnable('node-b', $this->enableData());

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([['sendReady', 'node-b']], $this->mesh->calls);
    }

    public function testLeaderAnswersMovedInitiatorUnderAFreezeThatStands(): void
    {
        $this->mesh->followers = ['node-b'];
        $this->mesh->location = AgentLocation::onNode('node-c');
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->stopTheRoster();
        $this->coordinator->onQuiesced('node-b');
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onEnable('node-c', $this->enableDataFrom('node-c'));

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([['sendReady', 'node-c']], $this->mesh->calls);
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
        $this->stopTheRoster();
        $this->coordinator->onQuiesced('node-b');
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onEnable('node-b', new ProtectedModeEnableSignalData(
            operation: 'restore',
            initiatorAcceptKey: 'accept-9',
            initiatorSessionTokenHash: null,
            initiatorAgentType: 'protected-mode-driver',
            initiatorAgentIndex: 0,
            initiatorNodeId: 'node-b',
        ));

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([
            ['sendRefused', 'node-b', ProtectedModeRefusalCopy::FOREIGN_FREEZE],
        ], $this->mesh->calls);
    }

    public function testLeaderEntersAgainFromTheVerificationWindowAndAnswersOnlyOnceEveryNodeQuiesced(): void
    {
        $this->mesh->followers = ['node-b'];
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->stopTheRoster();
        $this->coordinator->onQuiesced('node-b');
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->openTheVerificationWindowOnTheRuntimeRow();
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $secondEnable = new ProtectedModeEnableSignalData(
            operation: 'restore',
            initiatorAcceptKey: 'accept-second',
            initiatorSessionTokenHash: 'session-second',
            initiatorAgentType: 'backup',
            initiatorAgentIndex: 0,
            initiatorNodeId: 'node-b',
        );
        $this->coordinator->onEnable('node-b', $secondEnable);

        $this->assertSame(['enterActivating'], $this->executor->calls);
        $this->assertSame('accept-second', $this->executor->activatingAcceptKey);
        $this->assertSame('session-second', $this->executor->activatingSessionTokenHash);
        $this->assertSame([['broadcastQuiesce', 'restore']], $this->mesh->calls);

        $this->stopTheRoster();

        $this->assertSame(['enterActivating'], $this->executor->calls);
        $this->assertSame([['broadcastQuiesce', 'restore']], $this->mesh->calls);

        $this->coordinator->onQuiesced('node-b');

        $this->assertSame(['enterActivating', 'enterActive'], $this->executor->calls);
        $this->assertSame([['broadcastQuiesce', 'restore'], ['broadcastSettled', null], ['sendReady', 'node-b']], $this->mesh->calls);
    }

    public function testLeaderEnteringAgainWaitsForItsOwnRoster(): void
    {
        $this->mesh->followers = ['node-b'];
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->stopTheRoster();
        $this->coordinator->onQuiesced('node-b');
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->openTheVerificationWindowOnTheRuntimeRow();
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onEnable('node-b', new ProtectedModeEnableSignalData(
            operation: 'restore',
            initiatorAcceptKey: 'accept-second',
            initiatorSessionTokenHash: 'session-second',
            initiatorAgentType: 'backup',
            initiatorAgentIndex: 0,
            initiatorNodeId: 'node-b',
        ));

        $this->coordinator->onQuiesced('node-b');

        $this->assertSame(['enterActivating'], $this->executor->calls);
        $this->assertSame([['broadcastQuiesce', 'restore']], $this->mesh->calls);

        $this->stopTheRoster();

        $this->assertSame(['enterActivating', 'enterActive'], $this->executor->calls);
        $this->assertSame([['broadcastQuiesce', 'restore'], ['broadcastSettled', null], ['sendReady', 'node-b']], $this->mesh->calls);
    }

    public function testLeaderAcceptsMovedInitiatorUnderVerifyingWindow(): void
    {
        $this->mesh->followers = ['node-b'];
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->stopTheRoster();
        $this->coordinator->onQuiesced('node-b');
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->openTheVerificationWindowOnTheRuntimeRow();
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onEnable('node-c', $this->enableDataFrom('node-c'));

        $this->assertSame(['enterActivating'], $this->executor->calls);
        $this->assertSame([['broadcastQuiesce', 'restore']], $this->mesh->calls);
    }

    public function testFollowerRequestEnableWithoutKnownLeaderDeliversRefusalLocally(): void
    {
        $this->mesh->leader = null;

        $this->coordinator->requestEnable($this->enableDataFrom(self::SELF));

        $this->assertSame([], $this->mesh->calls);
        $this->assertSame([
            [
                'agentType' => 'backup',
                'agentIndex' => '0',
                'reason' => ProtectedModeRefusalCopy::NO_LEADER,
            ],
        ], $this->relay->refusedCalls);
    }

    public function testFollowerInitiatorNodeRelaysRefusedFromLeaderToLocalRelay(): void
    {
        $this->mesh->leader = 'node-x';

        $this->coordinator->requestEnable($this->enableDataFrom(self::SELF));
        $this->assertSame([['sendEnable', 'node-x']], $this->mesh->calls);

        $this->coordinator->onRefused('node-x', ProtectedModeRefusalCopy::FOREIGN_FREEZE);

        $this->assertSame([
            [
                'agentType' => 'backup',
                'agentIndex' => '0',
                'reason' => ProtectedModeRefusalCopy::FOREIGN_FREEZE,
            ],
        ], $this->relay->refusedCalls);
    }

    public function testInitiatorNodeRelaysReadyAgainWhenItsAgentAsksForAnotherFreeze(): void
    {
        // The once-per-freeze guard is re-armed by this node's own request, and it has to be:
        // a freeze that already stands sends no second quiesce, which is the other place the
        // guard is cleared, so the answer to the next operation would be swallowed.
        $this->mesh->leader = 'node-x';
        $this->coordinator->onQuiesce('node-x', new ProtectedModeQuiesceData('restore', 'backup', 0, self::SELF, null));
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

    public function testFollowerFreezesLocallyAndReportsQuiescedOnceItsRosterHasStopped(): void
    {
        $freeze = new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', null);

        $this->coordinator->onQuiesce('node-x', $freeze);

        $this->assertSame(['enterActivating'], $this->executor->calls);
        $this->assertNull($this->executor->activatingAcceptKey);
        // The promise onQuiesce() makes: no quiesced report for a node still serving its clients.
        $this->assertSame([], $this->mesh->calls);

        $this->stopTheRoster();

        $this->assertSame([['sendQuiesced', 'node-x']], $this->mesh->calls);
    }

    public function testFollowerOpensADirectWindowAndReportsQuiescedImmediately(): void
    {
        $window = new ProtectedModeQuiesceData('manual_maintenance', 'index', null, 'node-b', 'operator-hash');

        $this->coordinator->onWindow('node-x', $window);

        $this->assertSame(['enterVerificationWindow'], $this->executor->calls);
        $this->assertSame($window, $this->executor->freeze);
        $this->assertNull($this->executor->windowAcceptKey);
        $this->assertSame([['sendQuiesced', 'node-x']], $this->mesh->calls);
    }

    public function testFollowerAcknowledgesRepeatedWindowWithoutReenteringOrClearingAdmission(): void
    {
        $window = new ProtectedModeQuiesceData('manual_maintenance', 'index', null, 'node-b', 'operator-hash');
        $this->coordinator->onWindow('node-x', $window);
        $this->writeDirectWindowOnRuntimeRow($window);
        $this->coordinator->onReady('node-x');
        $this->withDaemonTruthSource(static function (): void {
            Hilos::$rt?->hilosProtectedModeRuntime?->actions->issuePass('pass-hash');
            Hilos::$rt?->hilosProtectedModeRuntime?->actions->admitSession('admitted-hash');
            Hilos::$rt?->hilosProtectedModeRuntime?->actions->admitCircle(new VerifierCircleSnapshot(2, ['circle-hash']));
        });

        $this->coordinator->onWindow('node-x', $window);
        $this->coordinator->onWindow('node-x', $window);
        $this->coordinator->onReady('node-x');

        $this->assertSame(['enterVerificationWindow', 'notifyInitiatorReady'], $this->executor->calls);
        $this->assertSame([
            ['sendQuiesced', 'node-x'], ['sendQuiesced', 'node-x'], ['sendQuiesced', 'node-x'],
        ], $this->mesh->calls);
        $this->assertSame(StateProtectedModeRuntime::PHASE_VERIFYING, Hilos::$rt?->hilosProtectedModeRuntime?->phase);
        $this->assertSame(['pass-hash'], Hilos::$rt?->hilosProtectedModeRuntime?->passHashes);
        $this->assertSame(['admitted-hash'], Hilos::$rt?->hilosProtectedModeRuntime?->admittedSessionTokenHashes);
        $this->assertSame(['circle-hash'], Hilos::$rt?->hilosProtectedModeRuntime?->circleSessionTokenHashes);
        $this->assertSame(2, Hilos::$rt?->hilosProtectedModeRuntime?->circleNamedCount);
        $this->assertSame('operator-hash', Hilos::$rt?->hilosProtectedModeRuntime?->initiatorSessionTokenHash);
    }

    public function testFollowerAcceptsRepeatedWindowFromTheCurrentNewLeader(): void
    {
        $window = new ProtectedModeQuiesceData('manual_maintenance', 'index', 0, 'node-b', null);
        $this->coordinator->onWindow('node-x', $window);
        $this->writeDirectWindowOnRuntimeRow($window);
        $this->mesh->leader = 'node-y';
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onWindow('node-y', $window);
        $this->coordinator->onLift('node-x');

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([['sendQuiesced', 'node-y']], $this->mesh->calls);

        $this->coordinator->onLift('node-y');

        $this->assertSame(['enterInactive', 'finishLift'], $this->executor->calls);
    }

    public function testFollowerDropsDirectWindowFromAnotherLeader(): void
    {
        $window = new ProtectedModeQuiesceData('manual_maintenance', 'index', null, 'node-b', null);
        $this->coordinator->onWindow('node-x', $window);
        $this->writeDirectWindowOnRuntimeRow($window);
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onWindow('node-other', $window);

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testFollowerDropsDirectWindowForAnotherInitiatorTypeOrIndex(): void
    {
        $window = new ProtectedModeQuiesceData('manual_maintenance', 'index', 0, 'node-b', null);
        $this->coordinator->onWindow('node-x', $window);
        $this->writeDirectWindowOnRuntimeRow($window);
        $this->executor->calls = [];
        $this->mesh->calls = [];

        foreach ([['backup', 0], ['index', 1], ['index', null]] as [$agentType, $agentIndex]) {
            $this->coordinator->onWindow('node-x', new ProtectedModeQuiesceData(
                'manual_maintenance', $agentType, $agentIndex, 'node-b', null,
            ));
        }

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
        $this->assertSame('index', Hilos::$rt?->hilosProtectedModeRuntime?->initiatorAgentType);
        $this->assertSame(0, Hilos::$rt?->hilosProtectedModeRuntime?->initiatorAgentIndex);
    }

    public function testFollowerCannotTurnAFullFreezeIntoADirectWindow(): void
    {
        $freeze = new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', null);
        $this->coordinator->onQuiesce('node-x', $freeze);
        $this->stopTheRoster();
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onWindow('node-x', $freeze);
        $this->assertSame(StateProtectedModeRuntime::PHASE_ACTIVATING, Hilos::$rt?->hilosProtectedModeRuntime?->phase);

        $this->settleTheFreezeOnTheRuntimeRow();
        $this->coordinator->onWindow('node-x', $freeze);
        $this->assertSame(StateProtectedModeRuntime::PHASE_ACTIVE, Hilos::$rt?->hilosProtectedModeRuntime?->phase);

        $this->openTheVerificationWindowOnTheRuntimeRow();
        $this->coordinator->onWindow('node-x', $freeze);
        $this->assertSame(StateProtectedModeRuntime::PHASE_VERIFYING, Hilos::$rt?->hilosProtectedModeRuntime?->phase);
        $this->assertSame(StateProtectedModeRuntime::ENTRY_MODE_FREEZE, Hilos::$rt?->hilosProtectedModeRuntime?->entryMode);
        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testFollowerWithoutRuntimeStateDoesNotAcknowledgeADirectWindow(): void
    {
        Hilos::$rt = null;
        $window = new ProtectedModeQuiesceData('manual_maintenance', 'index', null, 'node-b', null);

        $this->coordinator->onWindow('node-x', $window);

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);

        $this->mount();
        $this->coordinator->onWindow('node-y', $window);

        $this->assertSame(['enterVerificationWindow'], $this->executor->calls);
        $this->assertSame([['sendQuiesced', 'node-y']], $this->mesh->calls);
    }

    public function testFollowerDropsQuiesceDuringADirectWindow(): void
    {
        $window = new ProtectedModeQuiesceData('manual_maintenance', 'index', null, 'node-b', null);
        $this->coordinator->onWindow('node-x', $window);
        $this->writeDirectWindowOnRuntimeRow($window);
        $this->mesh->leader = 'node-y';
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onQuiesce('node-x', $window);
        $this->coordinator->onQuiesce('node-y', $window);
        $this->coordinator->onQuiesce('node-other', $window);

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
        $this->assertSame(StateProtectedModeRuntime::PHASE_VERIFYING, Hilos::$rt?->hilosProtectedModeRuntime?->phase);
        $this->assertSame(StateProtectedModeRuntime::ENTRY_MODE_VERIFICATION_WINDOW, Hilos::$rt?->hilosProtectedModeRuntime?->entryMode);
    }

    public function testFollowerReleasesOnLift(): void
    {
        $this->coordinator->onQuiesce('node-x', new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', null));
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
        $this->coordinator->onQuiesce('node-x', new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', null));
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onLift('node-y');

        $this->assertSame([], $this->executor->calls);
    }

    public function testFollowerFollowsNewLeaderForRepeatRoundAndLift(): void
    {
        $freeze = new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', null);
        $this->coordinator->onQuiesce('node-x', $freeze);
        $this->stopTheRoster();
        $this->openTheVerificationWindowOnTheRuntimeRow();
        $this->mesh->leader = 'node-y';
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onQuiesce('node-y', $freeze);
        $this->stopTheRoster();

        $this->assertSame(['enterActivating'], $this->executor->calls);
        $this->assertSame([['sendQuiesced', 'node-y']], $this->mesh->calls);

        $this->executor->calls = [];
        $this->coordinator->onLift('node-x');
        $this->assertSame([], $this->executor->calls);

        $this->coordinator->onLift('node-y');
        $this->assertSame(['enterInactive'], $this->executor->calls);
    }

    public function testFollowerAcceptsWindowFramesAndReadyFromNewLeader(): void
    {
        $this->coordinator->onQuiesce('node-x', new ProtectedModeQuiesceData('restore', 'backup', 0, self::SELF, null));
        $this->stopTheRoster();
        $this->mesh->leader = 'node-y';
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onSettled('node-y');
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->coordinator->onVerify('node-y', 'backup', 0);
        $this->openTheVerificationWindowOnTheRuntimeRow();
        $this->withDaemonTruthSource(function (): void {
            $this->coordinator->onPass('node-y', 'backup', 0, 'pass-hash');
            $this->coordinator->onCircle('node-y', 'backup', 0, $this->circleSnapshot());
        });
        $this->coordinator->onReady('node-y');

        $this->assertSame(['enterActive', 'enterVerifying', 'announcePassIssued', 'notifyInitiatorReady'], $this->executor->calls);
        $this->assertSame(['pass-hash'], Hilos::$rt?->hilosProtectedModeRuntime?->passHashes);
        $this->assertSame(['hash-a'], Hilos::$rt?->hilosProtectedModeRuntime?->circleSessionTokenHashes);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testFormerLeaderAcceptsLiftFromTheNewLeader(): void
    {
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->coordinator->onBecameLeader();
        $this->coordinator->onLostLeadership();
        $this->mesh->leader = 'node-y';

        $this->coordinator->onLift('node-y');

        $this->assertSame(['enterInactive'], $this->executor->calls);
    }

    public function testRestartedFollowerRefusesFreshQuiesceAndAcceptsCurrentLeadersLift(): void
    {
        // A restored runtime row has a freeze but no in-memory freezing leader.
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->mesh->leader = 'node-y';

        $this->coordinator->onQuiesce('node-y', new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', null));
        $this->assertSame([], $this->executor->calls);

        $this->coordinator->onLift('node-y');
        $this->assertSame(['enterInactive'], $this->executor->calls);
    }

    public function testCurrentLeadersLiftOnAnUnfrozenNodeDoesNothing(): void
    {
        $this->mesh->leader = 'node-y';

        $this->coordinator->onLift('node-y');

        $this->assertSame([], $this->executor->calls);
    }

    public function testFollowerIgnoresARepeatQuiesceWhileAlreadyFrozen(): void
    {
        $freeze = new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', null);
        $this->coordinator->onQuiesce('node-x', $freeze);
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onQuiesce('node-x', $freeze);

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testAFollowerInsideTheWindowEntersAgainOnItsLeadersQuiesceAndReportsOnceStopped(): void
    {
        $freeze = new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', null);
        $this->coordinator->onQuiesce('node-x', $freeze);
        $this->stopTheRoster();
        $this->openTheVerificationWindowOnTheRuntimeRow();
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onQuiesce('node-x', $freeze);

        $this->assertSame(['enterActivating'], $this->executor->calls);
        $this->assertNull($this->executor->activatingAcceptKey);

        $this->stopTheRoster();

        $this->assertSame([['sendQuiesced', 'node-x']], $this->mesh->calls);
    }

    public function testAFollowerInsideTheWindowIgnoresAQuiesceFromAnotherNode(): void
    {
        $freeze = new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', null);
        $this->coordinator->onQuiesce('node-x', $freeze);
        $this->stopTheRoster();
        $this->openTheVerificationWindowOnTheRuntimeRow();
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onQuiesce('node-y', $freeze);

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testAFollowerClosedBackIgnoresARepeatQuiesceFromItsLeader(): void
    {
        $freeze = new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', null);
        $this->coordinator->onQuiesce('node-x', $freeze);
        $this->stopTheRoster();
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onQuiesce('node-x', $freeze);

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testInitiatorNodeRelaysReadyToItsAgentOnceItsFreezingLeaderConfirms(): void
    {
        $this->coordinator->onQuiesce('node-x', new ProtectedModeQuiesceData('restore', 'backup', 0, self::SELF, null));
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onReady('node-x');

        $this->assertSame(['notifyInitiatorReady'], $this->executor->calls);
    }

    public function testInitiatorNodeIgnoresReadyFromANodeOtherThanItsFreezingLeader(): void
    {
        $this->coordinator->onQuiesce('node-x', new ProtectedModeQuiesceData('restore', 'backup', 0, self::SELF, null));
        $this->executor->calls = [];

        $this->coordinator->onReady('node-y');

        $this->assertSame([], $this->executor->calls);
    }

    public function testInitiatorNodeRelaysReadyOnlyOnceForRepeatedConfirmations(): void
    {
        $this->coordinator->onQuiesce('node-x', new ProtectedModeQuiesceData('restore', 'backup', 0, self::SELF, null));
        $this->executor->calls = [];

        $this->coordinator->onReady('node-x');
        $this->coordinator->onReady('node-x');

        $this->assertSame(['notifyInitiatorReady'], $this->executor->calls);
    }

    public function testLeaderInitiatorRelaysReadyLocallyOnceFollowersQuiesce(): void
    {
        $this->mesh->location = AgentLocation::here();
        $this->mesh->followers = ['node-b'];
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable(self::SELF, $this->enableDataFrom(self::SELF));
        $this->stopTheRoster();
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onQuiesced('node-b');

        // The leader is the initiator: the ready is handed to the local agent, never sent to itself.
        $this->assertSame(['enterActive', 'notifyInitiatorReady'], $this->executor->calls);
        $this->assertSame([['broadcastSettled', null]], $this->mesh->calls);
    }

    public function testInitiatorLeaderHandlesEnableRequestLocally(): void
    {
        $this->mesh->location = AgentLocation::here();
        $this->mesh->followers = [];
        $this->coordinator->onBecameLeader();

        $this->coordinator->requestEnable($this->enableDataFrom(self::SELF));
        $this->stopTheRoster();

        // Routed straight into the leader flow; the leader is the initiator, so the ready is relayed
        // to the local agent instead of being sent over the peer channel to itself.
        $this->assertSame(['enterActivating', 'enterActive', 'notifyInitiatorReady'], $this->executor->calls);
        $this->assertSame([['broadcastQuiesce', 'restore'], ['broadcastSettled', null]], $this->mesh->calls);
    }

    public function testInitiatorFollowerSendsEnableRequestToLeader(): void
    {
        $this->mesh->leader = 'node-z';

        $this->coordinator->requestEnable($this->enableDataFrom(self::SELF));

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([['sendEnable', 'node-z']], $this->mesh->calls);
    }

    public function testLeaderDirectWindowWaitsOnlyForEveryFollowerBeforeReady(): void
    {
        $this->recordDirectWindowTransitions();
        $this->mesh->followers = ['node-b', 'node-c'];
        $this->coordinator->onBecameLeader();

        $this->coordinator->onEnable('node-b', $this->directWindowData());

        $this->assertSame(['enterVerificationWindow'], $this->executor->calls);
        $this->assertSame('operator-accept', $this->executor->windowAcceptKey);
        $this->assertSame('operator-hash', $this->executor->freeze?->initiatorSessionTokenHash);
        $this->assertSame('node-b', $this->executor->freeze?->initiatorNodeId);
        $this->assertSame([['broadcastWindow', 'manual_maintenance']], $this->mesh->calls);
        $this->assertSame(['node-b', 'node-c'], $this->coordinator->pendingNodeIds());

        $this->coordinator->onRosterStopped();
        $this->coordinator->onQuiesced('node-other');
        $this->coordinator->onQuiesced('node-b');
        $this->coordinator->onQuiesced('node-b');

        $this->assertSame(['node-c'], $this->coordinator->pendingNodeIds());
        $this->assertSame(['enterVerificationWindow'], $this->executor->calls);
        $this->assertSame([['broadcastWindow', 'manual_maintenance']], $this->mesh->calls);

        $this->coordinator->onQuiesced('node-c');
        $this->coordinator->onQuiesced('node-c');

        $this->assertSame(['enterVerificationWindow'], $this->executor->calls);
        $this->assertSame([['broadcastWindow', 'manual_maintenance'], ['sendReady', 'node-b']], $this->mesh->calls);
        $this->assertSame([], $this->coordinator->pendingNodeIds());
        $this->assertSame(StateProtectedModeRuntime::PHASE_VERIFYING, Hilos::$rt?->hilosProtectedModeRuntime?->phase);
        $this->assertSame(StateProtectedModeRuntime::ENTRY_MODE_VERIFICATION_WINDOW, Hilos::$rt?->hilosProtectedModeRuntime?->entryMode);
    }

    public function testSingleNodeLeaderDirectWindowAnswersReadyWithoutARosterWalk(): void
    {
        $this->recordDirectWindowTransitions();
        $this->mesh->location = AgentLocation::here();
        $this->coordinator->onBecameLeader();

        $this->coordinator->requestEnable($this->directWindowData(initiatorNodeId: self::SELF));
        $this->coordinator->onRosterStopped();

        $this->assertSame(['enterVerificationWindow', 'notifyInitiatorReady'], $this->executor->calls);
        $this->assertSame([['broadcastWindow', 'manual_maintenance']], $this->mesh->calls);
        $this->assertSame([], $this->coordinator->pendingNodeIds());
        $this->assertSame(StateProtectedModeRuntime::PHASE_VERIFYING, Hilos::$rt?->hilosProtectedModeRuntime?->phase);
    }

    public function testFollowerDirectWindowRequestForwardsAndRearmsTheReadyRelay(): void
    {
        $this->recordDirectWindowTransitions();
        $this->mesh->leader = 'node-x';
        $this->coordinator->requestEnable($this->directWindowData(initiatorNodeId: self::SELF));

        $this->assertSame([['sendEnable', 'node-x']], $this->mesh->calls);
        $this->assertSame([], $this->executor->calls);

        $this->coordinator->onWindow('node-x', new ProtectedModeQuiesceData(
            'manual_maintenance', 'index', null, self::SELF, 'operator-hash',
        ));
        $this->coordinator->onReady('node-x');
        $this->coordinator->onReady('node-x');
        $this->coordinator->requestEnable($this->directWindowData(initiatorNodeId: self::SELF));
        $this->coordinator->onReady('node-x');
        $this->coordinator->onReady('node-x');

        $this->assertSame(['enterVerificationWindow', 'notifyInitiatorReady', 'notifyInitiatorReady'], $this->executor->calls);
        $this->assertSame([
            ['sendEnable', 'node-x'], ['sendQuiesced', 'node-x'], ['sendEnable', 'node-x'],
        ], $this->mesh->calls);
    }

    public function testDirectWindowRepeatDuringItsRoundIsRefusedWithoutAnotherEntry(): void
    {
        $this->recordDirectWindowTransitions();
        $this->mesh->followers = ['node-b'];
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->directWindowData());
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onEnable('node-b', $this->directWindowData());

        $this->assertSame([], $this->executor->calls);
        $this->assertSame(['node-b'], $this->coordinator->pendingNodeIds());
        $this->assertSame([
            ['sendRefused', 'node-b', ProtectedModeRefusalCopy::ANOTHER_OPERATION],
        ], $this->mesh->calls);
    }

    public function testDirectWindowRepeatAnswersCurrentPlacementAndKeepsAdmission(): void
    {
        $this->enterDirectWindowOnLeader();
        $this->withDaemonTruthSource(static function (): void {
            Hilos::$rt?->hilosProtectedModeRuntime?->actions->issuePass('pass-hash');
            Hilos::$rt?->hilosProtectedModeRuntime?->actions->admitSession('admitted-hash');
            Hilos::$rt?->hilosProtectedModeRuntime?->actions->admitCircle(new VerifierCircleSnapshot(2, ['circle-hash']));
        });
        $before = Hilos::$rt?->hilosProtectedModeRuntime?->toArray();
        $this->mesh->location = AgentLocation::onNode('node-c');

        $this->coordinator->onEnable('node-c', $this->directWindowData(initiatorNodeId: 'node-c'));

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([['sendReady', 'node-c']], $this->mesh->calls);
        $this->assertSame($before, Hilos::$rt?->hilosProtectedModeRuntime?->toArray());
    }

    public function testDirectWindowRefusesAnotherOperationOrEntryMode(): void
    {
        $this->enterDirectWindowOnLeader();

        $this->coordinator->onEnable('node-b', $this->directWindowData(operation: 'other-operation'));
        $this->coordinator->onEnable('node-b', new ProtectedModeEnableSignalData(
            'manual_maintenance', null, null, 'index', null, 'node-b',
        ));

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([
            ['sendRefused', 'node-b', ProtectedModeRefusalCopy::ANOTHER_OPERATION],
            ['sendRefused', 'node-b', ProtectedModeRefusalCopy::ANOTHER_OPERATION],
        ], $this->mesh->calls);
        $this->assertSame('manual_maintenance', Hilos::$rt?->hilosProtectedModeRuntime?->operation);
        $this->assertSame(StateProtectedModeRuntime::ENTRY_MODE_VERIFICATION_WINDOW, Hilos::$rt?->hilosProtectedModeRuntime?->entryMode);
    }

    public function testDirectWindowRefusesForeignInitiatorTypeOrIndex(): void
    {
        $this->enterDirectWindowOnLeader();

        $this->coordinator->onEnable('node-b', $this->directWindowData(agentType: 'backup'));
        $this->coordinator->onEnable('node-b', $this->directWindowData(agentIndex: 0));

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([
            ['sendRefused', 'node-b', ProtectedModeRefusalCopy::FOREIGN_FREEZE],
            ['sendRefused', 'node-b', ProtectedModeRefusalCopy::FOREIGN_FREEZE],
        ], $this->mesh->calls);
    }

    public function testFullFreezeRefusesDirectEntryOnActiveAndVerifying(): void
    {
        $this->mesh->followers = ['node-b'];
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->stopTheRoster();
        $this->coordinator->onQuiesced('node-b');
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onEnable('node-b', $this->directWindowData('restore', 'backup', 0));
        $this->openTheVerificationWindowOnTheRuntimeRow();
        $this->coordinator->onEnable('node-b', $this->directWindowData('restore', 'backup', 0));

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([
            ['sendRefused', 'node-b', ProtectedModeRefusalCopy::ANOTHER_OPERATION],
            ['sendRefused', 'node-b', ProtectedModeRefusalCopy::ANOTHER_OPERATION],
        ], $this->mesh->calls);
        $this->assertSame(StateProtectedModeRuntime::ENTRY_MODE_FREEZE, Hilos::$rt?->hilosProtectedModeRuntime?->entryMode);
    }

    public function testLeaderDirectWindowStillRequiresLeadershipInitiatorNodeAndRuntime(): void
    {
        $this->coordinator->onEnable('node-b', $this->directWindowData());
        $this->assertSame([['sendRefused', 'node-b', ProtectedModeRefusalCopy::NO_LEADER]], $this->mesh->calls);

        $this->coordinator->onBecameLeader();
        $this->mesh->calls = [];
        $this->coordinator->onEnable('node-b', $this->directWindowData(initiatorNodeId: null));
        $this->assertSame([['sendRefused', 'node-b', ProtectedModeRefusalCopy::ANOTHER_OPERATION]], $this->mesh->calls);

        Hilos::$rt = null;
        $this->mesh->calls = [];
        $this->coordinator->onEnable('node-b', $this->directWindowData());

        $this->assertSame([['sendRefused', 'node-b', ProtectedModeRefusalCopy::NO_RUNTIME_ROW]], $this->mesh->calls);
        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->coordinator->pendingNodeIds());

        $this->mount();
        $this->recordDirectWindowTransitions();
        $this->mesh->calls = [];
        $this->coordinator->onEnable('node-b', $this->directWindowData());

        $this->assertSame(['enterVerificationWindow'], $this->executor->calls);
        $this->assertSame([['broadcastWindow', 'manual_maintenance'], ['sendReady', 'node-b']], $this->mesh->calls);
    }

    public function testLeaderDirectWindowAcceptsTheCircleOnceAndFinishesVerifying(): void
    {
        $this->enterDirectWindowOnLeader();

        $this->withDaemonTruthSource(fn() => $this->coordinator->onCircle('node-b', 'index', null, $this->circleSnapshot()));
        $this->coordinator->onCircle('node-b', 'index', null, new VerifierCircleSnapshot(0, []));

        $this->assertSame(['finishVerifying'], $this->executor->calls);
        $this->assertSame([['broadcastCircle', '1']], $this->mesh->calls);
        $this->assertSame(['hash-a'], Hilos::$rt?->hilosProtectedModeRuntime?->circleSessionTokenHashes);
        $this->assertSame(1, Hilos::$rt?->hilosProtectedModeRuntime?->circleNamedCount);
    }

    public function testAnEmptyDirectCirclePhotographAlsoFinishesAndCannotBeReplaced(): void
    {
        $this->enterDirectWindowOnLeader();

        $this->withDaemonTruthSource(fn() => $this->coordinator->requestCircle(new ProtectedModeCircleSignalData(
            'index', null, 0, [],
        )));
        $this->coordinator->onEnable('node-b', $this->directWindowData());
        $this->coordinator->onCircle('node-b', 'index', null, $this->circleSnapshot());

        $this->assertSame(['finishVerifying'], $this->executor->calls);
        $this->assertSame([['broadcastCircle', '0'], ['sendReady', 'node-b']], $this->mesh->calls);
        $this->assertSame([], Hilos::$rt?->hilosProtectedModeRuntime?->circleSessionTokenHashes);
        $this->assertSame(0, Hilos::$rt?->hilosProtectedModeRuntime?->circleNamedCount);
    }

    public function testLeaderDirectWindowDropsForeignCircleDisableAndRefreeze(): void
    {
        $this->enterDirectWindowOnLeader();

        $this->coordinator->onCircle('node-b', 'backup', null, $this->circleSnapshot());
        $this->coordinator->onCircle('node-b', 'index', 0, $this->circleSnapshot());
        $this->coordinator->onDisable('node-b', 'backup', null);
        $this->coordinator->onDisable('node-b', 'index', 0);
        $this->coordinator->onRefreeze('node-b', 'index', null);

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
        $this->assertSame([], Hilos::$rt?->hilosProtectedModeRuntime?->circleSessionTokenHashes);
        $this->assertSame(StateProtectedModeRuntime::PHASE_VERIFYING, Hilos::$rt?->hilosProtectedModeRuntime?->phase);
    }

    public function testFollowerDirectWindowFinishesOnlyWhenTheLeadersCircleArrives(): void
    {
        $this->recordDirectWindowTransitions();
        $this->mesh->leader = 'node-x';
        $this->coordinator->onWindow('node-x', new ProtectedModeQuiesceData(
            'manual_maintenance', 'index', null, self::SELF, 'operator-hash',
        ));
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->withDaemonTruthSource(fn() => $this->coordinator->requestCircle(new ProtectedModeCircleSignalData(
            'index', null, 1, ['hash-a'],
        )));
        $this->coordinator->onCircle('node-other', 'index', null, new VerifierCircleSnapshot(0, []));
        $this->coordinator->onSettled('node-x');

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([['sendCircle', 'node-x']], $this->mesh->calls);
        $this->assertSame(['hash-a'], Hilos::$rt?->hilosProtectedModeRuntime?->circleSessionTokenHashes);

        $this->withDaemonTruthSource(fn() => $this->coordinator->onCircle('node-x', 'index', null, $this->circleSnapshot()));

        $this->assertSame(['finishVerifying'], $this->executor->calls);
        $this->assertSame([['sendCircle', 'node-x']], $this->mesh->calls);
        $this->assertSame(['hash-a'], Hilos::$rt?->hilosProtectedModeRuntime?->circleSessionTokenHashes);
    }

    public function testFollowerDirectWindowFinishesOnAnEmptyLeadersCircleToo(): void
    {
        $this->recordDirectWindowTransitions();
        $this->coordinator->onWindow('node-x', new ProtectedModeQuiesceData(
            'manual_maintenance', 'index', null, 'node-b', null,
        ));
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->withDaemonTruthSource(fn() => $this->coordinator->onCircle('node-x', 'index', null, new VerifierCircleSnapshot(0, [])));

        $this->assertSame(['finishVerifying'], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
        $this->assertSame(0, Hilos::$rt?->hilosProtectedModeRuntime?->circleNamedCount);
    }

    public function testLeaderDirectWindowLiftsImmediatelyAndNextWindowTakesANewCircle(): void
    {
        $this->enterDirectWindowOnLeader();
        $this->withDaemonTruthSource(fn() => $this->coordinator->onCircle('node-b', 'index', null, $this->circleSnapshot()));
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onDisable('node-b', 'index', null);

        $this->assertSame(['enterInactive', 'finishLift'], $this->executor->calls);
        $this->assertSame([['broadcastLift', null]], $this->mesh->calls);
        $this->assertSame(StateProtectedModeRuntime::PHASE_INACTIVE, Hilos::$rt?->hilosProtectedModeRuntime?->phase);
        $this->assertNull(Hilos::$rt?->hilosProtectedModeRuntime?->entryMode);
        $this->assertSame([], $this->coordinator->pendingNodeIds());

        $this->executor->calls = [];
        $this->mesh->calls = [];
        $this->coordinator->onEnable('node-b', $this->directWindowData());
        $this->coordinator->onQuiesced('node-b');
        $this->withDaemonTruthSource(fn() => $this->coordinator->onCircle('node-b', 'index', null, new VerifierCircleSnapshot(0, [])));

        $this->assertSame(['enterVerificationWindow', 'finishVerifying'], $this->executor->calls);
        $this->assertSame([
            ['broadcastWindow', 'manual_maintenance'], ['sendReady', 'node-b'], ['broadcastCircle', '0'],
        ], $this->mesh->calls);
        $this->assertSame([], Hilos::$rt?->hilosProtectedModeRuntime?->circleSessionTokenHashes);
    }

    public function testFollowerDirectWindowLiftsImmediatelyOnlyForItsLeader(): void
    {
        $this->recordDirectWindowTransitions();
        $this->coordinator->onWindow('node-x', new ProtectedModeQuiesceData(
            'manual_maintenance', 'index', null, 'node-b', null,
        ));
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onLift('node-other');
        $this->assertSame([], $this->executor->calls);
        $this->coordinator->onLift('node-x');
        $this->coordinator->onLift('node-x');

        $this->assertSame(['enterInactive', 'finishLift'], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
        $this->assertSame(StateProtectedModeRuntime::PHASE_INACTIVE, Hilos::$rt?->hilosProtectedModeRuntime?->phase);
        $this->assertNull(Hilos::$rt?->hilosProtectedModeRuntime?->entryMode);
    }

    public function testPromotedLeaderAdoptsDirectWindowWithoutAnotherReadyOrPhotograph(): void
    {
        $this->recordDirectWindowTransitions();
        $this->coordinator->onWindow('node-x', new ProtectedModeQuiesceData(
            'manual_maintenance', 'index', null, 'node-b', 'operator-hash',
        ));
        $this->mesh->followers = ['node-b', 'node-c'];
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onBecameLeader();
        $this->coordinator->onQuiesced('node-b');
        $this->coordinator->onCircle('node-b', 'index', null, $this->circleSnapshot());

        $this->assertSame([], $this->coordinator->pendingNodeIds());
        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
        $this->assertSame([], Hilos::$rt?->hilosProtectedModeRuntime?->circleSessionTokenHashes);

        $this->coordinator->onEnable('node-b', $this->directWindowData());
        $this->coordinator->onDisable('node-b', 'backup', null);
        $this->coordinator->onRefreeze('node-b', 'index', null);
        $this->assertSame([['sendReady', 'node-b']], $this->mesh->calls);
        $this->assertSame([], $this->executor->calls);

        $this->coordinator->onDisable('node-b', 'index', null);
        $this->assertSame(['enterInactive', 'finishLift'], $this->executor->calls);
        $this->assertSame([['sendReady', 'node-b'], ['broadcastLift', null]], $this->mesh->calls);
    }

    public function testRestartedLeaderAdoptsDirectWindowFromItsRuntimeRow(): void
    {
        $this->recordDirectWindowTransitions();
        $this->writeDirectWindowOnRuntimeRow(new ProtectedModeQuiesceData(
            'manual_maintenance', 'index', null, 'node-b', null,
        ));
        $this->mesh->followers = ['node-b'];

        $this->coordinator->onBecameLeader();
        $this->coordinator->onCircle('node-b', 'index', null, $this->circleSnapshot());
        $this->coordinator->onEnable('node-b', $this->directWindowData());

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([['sendReady', 'node-b']], $this->mesh->calls);
        $this->assertSame([], $this->coordinator->pendingNodeIds());
        $this->assertSame([], Hilos::$rt?->hilosProtectedModeRuntime?->circleSessionTokenHashes);

        $this->coordinator->onDisable('node-b', 'index', null);
        $this->assertSame(['enterInactive', 'finishLift'], $this->executor->calls);
    }

    public function testDemotedDirectWindowLeaderAcceptsTheNewLeadersCircleAndLift(): void
    {
        $this->enterDirectWindowOnLeader();
        $this->coordinator->onLostLeadership();
        $this->mesh->leader = 'node-y';

        $this->coordinator->onQuiesced('node-b');
        $this->coordinator->onDisable('node-b', 'index', null);
        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
        $this->assertSame([], $this->coordinator->pendingNodeIds());

        $this->withDaemonTruthSource(fn() => $this->coordinator->onCircle('node-y', 'index', null, $this->circleSnapshot()));
        $this->coordinator->onLift('node-y');

        $this->assertSame(['finishVerifying', 'enterInactive', 'finishLift'], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
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

    public function testLeaderStampsAProgressMarkFromTheNodeThatOwnsTheFreeze(): void
    {
        $this->mesh->followers = [];
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $before = time();
        $this->withDaemonTruthSource(fn() => $this->coordinator->onProgress('node-b', 'backup', 0));

        $stamped = Hilos::$rt?->hilosProtectedModeRuntime?->progressAt;
        $this->assertNotNull($stamped);
        $this->assertGreaterThanOrEqual($before, $stamped);
        // The mark moves no phase and is never fanned onward: only the leader reads it.
        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testTheLeaderAnnouncesOnlyTheFirstPassAndFansEveryOneOnward(): void
    {
        // Two things are being separated here: every pass reaches the followers, because a
        // verifier may land on any node, while only the first one is announced to the browsers
        // this node already holds - it is the step that swaps their waiting sentence for a field.
        $this->mesh->followers = [];
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->openTheVerificationWindowOnTheRuntimeRow();
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->withDaemonTruthSource(function (): void {
            $this->coordinator->onPass('node-b', 'backup', 0, 'hash-a');
            $this->coordinator->onPass('node-b', 'backup', 0, 'hash-b');
        });

        $this->assertSame(['hash-a', 'hash-b'], Hilos::$rt?->hilosProtectedModeRuntime?->passHashes);
        $this->assertSame(['announcePassIssued'], $this->executor->calls);
        $this->assertSame([['broadcastPass', 'hash-a'], ['broadcastPass', 'hash-b']], $this->mesh->calls);
    }

    public function testAFollowerAnnouncesTheFirstPassItIsToldOfAndFansNothingOnward(): void
    {
        // The other end of the same broadcast: a follower records what the leader minted and owes
        // its own frozen browsers the same one announcement, but has nobody to pass it to.
        $this->coordinator->onQuiesce('node-x', new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', null));
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->openTheVerificationWindowOnTheRuntimeRow();
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->withDaemonTruthSource(function (): void {
            $this->coordinator->onPass('node-x', 'backup', 0, 'hash-a');
            $this->coordinator->onPass('node-x', 'backup', 0, 'hash-b');
        });

        $this->assertSame(['hash-a', 'hash-b'], Hilos::$rt?->hilosProtectedModeRuntime?->passHashes);
        $this->assertSame(['announcePassIssued'], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testQuiesceCarriesTheOperatorSessionHashToTheFollower(): void
    {
        $freeze = new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', 'operator-hash');

        $this->coordinator->onQuiesce('node-x', $freeze);

        $this->assertSame('operator-hash', $this->executor->freeze?->initiatorSessionTokenHash);
        $this->assertNull($this->executor->activatingAcceptKey);
    }

    public function testLeaderBroadcastsTheOperatorsSessionHashWithTheFreeze(): void
    {
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', new ProtectedModeEnableSignalData(
            'restore', 'accept-9', 'operator-hash', 'backup', 0, 'node-b',
        ));

        $this->assertSame('operator-hash', $this->mesh->lastQuiesce?->initiatorSessionTokenHash);
        $this->assertSame('operator-hash', $this->executor->activatingSessionTokenHash);
    }

    public function testRepeatEntryCarriesTheNewOperatorSessionHash(): void
    {
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->stopTheRoster();
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->openTheVerificationWindowOnTheRuntimeRow();

        $this->coordinator->onEnable('node-b', new ProtectedModeEnableSignalData(
            'restore', 'accept-new', 'new-operator-hash', 'backup', 0, 'node-b',
        ));

        $this->assertSame('new-operator-hash', $this->mesh->lastQuiesce?->initiatorSessionTokenHash);
        $this->assertSame('new-operator-hash', $this->executor->activatingSessionTokenHash);
    }

    public function testPromotedLeaderClosesTheWindowWithTheOperatorFromItsRow(): void
    {
        $this->settleTheFreezeOnTheRuntimeRow('accept-9', 'operator-hash');
        $this->openTheVerificationWindowOnTheRuntimeRow();
        $this->coordinator->onBecameLeader();

        $this->coordinator->onRefreeze('node-b', 'backup', 0);

        $this->assertSame('operator-hash', $this->mesh->lastQuiesce?->initiatorSessionTokenHash);
    }

    public function testLeaderAdmissionChecksTheCurrentPassAndBroadcasts(): void
    {
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->openTheVerificationWindowOnTheRuntimeRow();
        $this->mintPassOnRuntimeRow('pass-hash');
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->requestAdmit('pass-hash', 'verifier-hash');

        $this->assertSame(['admitVerifier'], $this->executor->calls);
        $this->assertSame([['broadcastAdmit', 'pass-hash']], $this->mesh->calls);

        $this->executor->calls = [];
        $this->mesh->calls = [];
        $this->coordinator->onAdmit('node-b', 'old-pass', 'other-session');
        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testFollowerRecordsAdmissionLocallyThenForwardsItToLeader(): void
    {
        $this->mesh->leader = 'node-x';

        $this->coordinator->requestAdmit('pass-hash', 'verifier-hash');

        $this->assertSame(['admitVerifier'], $this->executor->calls);
        $this->assertSame([['sendAdmit', 'node-x']], $this->mesh->calls);
    }

    public function testAdmissionStaysOnThePresentingMasterWithoutALeader(): void
    {
        $this->coordinator->requestAdmit('pass-hash', 'verifier-hash');

        $this->assertSame(['admitVerifier'], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testFollowerAcceptsOnlyItsLeadersAdmissionForACurrentPass(): void
    {
        $this->mesh->leader = 'node-x';
        $this->coordinator->onQuiesce('node-x', new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', null));
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->openTheVerificationWindowOnTheRuntimeRow();
        $this->mintPassOnRuntimeRow('pass-hash');
        $this->executor->calls = [];

        $this->coordinator->onAdmit('node-y', 'pass-hash', 'wrong-leader');
        $this->coordinator->onAdmit('node-x', 'old-pass', 'old-code');
        $this->assertSame([], $this->executor->calls);

        $this->coordinator->onAdmit('node-x', 'pass-hash', 'verifier-hash');
        $this->assertSame(['admitVerifier'], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testAdmissionOutsideTheVerificationWindowIsDropped(): void
    {
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onAdmit('node-b', 'pass-hash', 'verifier-hash');

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testAPassArrivingOutsideTheWindowIsRecordedNowhereAndAnnouncedToNobody(): void
    {
        $this->mesh->followers = [];
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onPass('node-b', 'backup', 0, 'hash-a');

        $this->assertSame([], Hilos::$rt?->hilosProtectedModeRuntime?->passHashes);
        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testTheLeaderWritesTheCircleAndFansItToEveryMaster(): void
    {
        // A member's tab connects to whichever node the balancer hands it, and each node decides
        // admission against its own copy of the row - so the photograph has to be on every master,
        // the leader's own among them.
        $this->mesh->followers = [];
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable(self::SELF, $this->enableDataFrom(self::SELF));
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->mesh->calls = [];

        $this->withDaemonTruthSource(fn() => $this->coordinator->requestCircle($this->circleData()));

        $this->assertSame(['hash-a'], Hilos::$rt?->hilosProtectedModeRuntime?->circleSessionTokenHashes);
        $this->assertSame(1, Hilos::$rt?->hilosProtectedModeRuntime?->circleNamedCount);
        $this->assertSame([['broadcastCircle', '1']], $this->mesh->calls);
    }

    public function testAFollowerWritesTheCircleOnItsOwnRowWithoutWaitingForActive(): void
    {
        // An initiator hosted on a follower reaches `active` only on its leader's settled frame
        // (HIL-1128), so a phase gate here would make the circle wait on one more frame on exactly
        // the topology that has one. The own row is written before the leader is asked to fan it,
        // so the node the initiator sits on does not wait on a round trip.
        $this->mesh->leader = 'node-x';
        $this->coordinator->onQuiesce('node-x', new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-a', null));
        $this->mesh->calls = [];

        $this->withDaemonTruthSource(fn() => $this->coordinator->requestCircle($this->circleData()));

        $this->assertSame(['hash-a'], Hilos::$rt?->hilosProtectedModeRuntime?->circleSessionTokenHashes);
        $this->assertSame([['sendCircle', 'node-x']], $this->mesh->calls);
    }

    public function testAFollowerThatKnowsNoLeaderKeepsTheCircleOnItsOwnRow(): void
    {
        // Nobody to fan it: the rest of the cluster lets the circle in by code alone, but the node
        // the initiator sits on still recognizes the members already attached to it.
        $this->coordinator->onQuiesce('node-x', new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-a', null));
        $this->mesh->calls = [];

        $this->withDaemonTruthSource(fn() => $this->coordinator->requestCircle($this->circleData()));

        $this->assertSame(['hash-a'], Hilos::$rt?->hilosProtectedModeRuntime?->circleSessionTokenHashes);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testANodeHoldingNoFreezeSendsTheCircleToTheLeaderAndWritesNothing(): void
    {
        // A slave: no freeze frame reaches it, so it holds no freeze row to write, while the leader
        // authorizes the photograph by the node that initiated the operation. Run without the truth
        // source on purpose: reaching the row here would throw.
        $this->mesh->leader = 'node-x';

        $this->coordinator->requestCircle($this->circleData());

        $this->assertSame([], Hilos::$rt?->hilosProtectedModeRuntime?->circleSessionTokenHashes);
        $this->assertSame([['sendCircle', 'node-x']], $this->mesh->calls);
    }

    public function testACircleOfferedUnderNoFreezeWithNoKnownLeaderIsDropped(): void
    {
        // Run without the truth source on purpose: reaching the row here would throw.
        $this->coordinator->requestCircle($this->circleData());

        $this->assertSame([], Hilos::$rt?->hilosProtectedModeRuntime?->circleSessionTokenHashes);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testAFollowerWritesTheCircleItsFreezingLeaderFansAndPassesItNowhere(): void
    {
        $this->coordinator->onQuiesce('node-x', new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', null));
        $this->mesh->calls = [];

        $this->withDaemonTruthSource(fn() => $this->coordinator->onCircle('node-x', 'backup', 0, $this->circleSnapshot()));

        $this->assertSame(['hash-a'], Hilos::$rt?->hilosProtectedModeRuntime?->circleSessionTokenHashes);
        $this->assertSame(1, Hilos::$rt?->hilosProtectedModeRuntime?->circleNamedCount);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testTheLeaderWritesAndFansTheCircleOfTheNodeThatInitiatedTheFreeze(): void
    {
        // The initiator sent it from a follower or from a slave; the leader treats both alike, and
        // the sender gets the same photograph back - a rewrite of what it already holds.
        $this->mesh->followers = ['node-b', 'node-c'];
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->mesh->calls = [];

        $this->withDaemonTruthSource(fn() => $this->coordinator->onCircle('node-b', 'backup', 0, $this->circleSnapshot()));

        $this->assertSame(['hash-a'], Hilos::$rt?->hilosProtectedModeRuntime?->circleSessionTokenHashes);
        $this->assertSame([['broadcastCircle', '1']], $this->mesh->calls);
    }

    public function testTheLeaderDropsACircleFromAnotherAgent(): void
    {
        // The same authorization every frame of the window is given. Run without the truth source
        // on purpose: reaching the row here would throw.
        $this->mesh->followers = ['node-b', 'node-c'];
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->mesh->calls = [];

        $this->coordinator->onCircle('node-c', 'other-agent', 0, $this->circleSnapshot());

        $this->assertSame([], Hilos::$rt?->hilosProtectedModeRuntime?->circleSessionTokenHashes);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testACircleArrivingAfterTheLiftIsRecordedNowhere(): void
    {
        // A frame that outlived its freeze: this node is no longer frozen and leads nothing. Run
        // without the truth source on purpose: reaching the row here would throw.
        $this->coordinator->onQuiesce('node-x', new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', null));
        $this->coordinator->onLift('node-x');
        $this->mesh->calls = [];

        $this->coordinator->onCircle('node-x', 'backup', 0, $this->circleSnapshot());

        $this->assertSame([], Hilos::$rt?->hilosProtectedModeRuntime?->circleSessionTokenHashes);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testLeaderDropsAProgressMarkFromAnotherAgent(): void
    {
        // Same authorization the release is given: an agent that did not ask for the freeze could
        // otherwise keep a hung operation looking alive on the leader's row indefinitely.
        $this->mesh->followers = [];
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->settleTheFreezeOnTheRuntimeRow();

        $this->withDaemonTruthSource(fn() => $this->coordinator->onProgress('node-c', 'other-agent', 0));

        $this->assertNull(Hilos::$rt?->hilosProtectedModeRuntime?->progressAt);
    }

    public function testAProgressMarkForAFreezeThisNodeDoesNotLeadIsDropped(): void
    {
        // The ordinary tail of an operation whose last marks outlived its freeze; run without the
        // truth source on purpose, because reaching the row here would throw.
        $this->coordinator->onProgress('node-b', 'backup', 0);

        $this->assertNull(Hilos::$rt?->hilosProtectedModeRuntime?->progressAt);
    }

    public function testInitiatorFollowerSendsItsProgressMarkToTheLeader(): void
    {
        $this->mesh->leader = 'node-z';

        $this->coordinator->requestProgress($this->progressData());

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([['sendProgress', 'node-z']], $this->mesh->calls);
    }

    public function testInitiatorDropsItsProgressMarkWhenNoLeaderIsKnown(): void
    {
        $this->coordinator->requestProgress($this->progressData());

        $this->assertSame([], $this->mesh->calls);
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
        $this->assertSame([
            ['sendRefused', 'node-b', ProtectedModeRefusalCopy::ANOTHER_OPERATION],
        ], $this->mesh->calls);
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
        $this->assertSame([
            ['sendRefused', 'node-b', ProtectedModeRefusalCopy::NO_RUNTIME_ROW],
        ], $this->mesh->calls);
    }

    public function testARefusedEnterLeavesNoFreezeBehind(): void
    {
        $this->mesh->followers = ['node-b'];
        $this->coordinator->onBecameLeader();
        Hilos::$rt = null;
        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->assertSame([
            ['sendRefused', 'node-b', ProtectedModeRefusalCopy::NO_RUNTIME_ROW],
        ], $this->mesh->calls);
        $this->mesh->calls = [];

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

        $this->coordinator->onQuiesce('node-x', new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', null));

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testAPromotedLeaderCanLiftTheFreezeItsNodeIsAlreadyUnder(): void
    {
        // The whole point of the rebuild: leader-side state dies with the leader, so a blank
        // successor drops the disable of a live and healthy initiator and NOTHING can unfreeze the
        // cluster - while the watchdog, by design, never lifts one either.
        $followerMesh = new FakeProtectedModeMesh();
        $followerExecutor = new FakeProtectedModeExecutor();
        $follower = new ClusterProtectedMode('node-b', $followerMesh, $followerExecutor);
        $follower->onQuiesce('node-x', new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', null));
        $followerExecutor->calls = [];
        $followerMesh->leader = self::SELF;

        $this->mesh->followers = ['node-b'];
        $this->settleTheFreezeOnTheRuntimeRow();

        $this->coordinator->onBecameLeader();
        $this->coordinator->onDisable('node-b', 'backup', 0);

        $this->assertSame(['enterDeactivating', 'enterInactive'], $this->executor->calls);
        $this->assertSame([['broadcastLift', null]], $this->mesh->calls);

        $follower->onLift(self::SELF);
        $this->assertSame(['enterInactive'], $followerExecutor->calls);
    }

    public function testAPromotedLeaderStillIgnoresADisableFromAnotherAgent(): void
    {
        // The identity is rebuilt from the row, not assumed: an inherited freeze is authorized the
        // same way one this leader ordered itself is.
        $this->mesh->followers = ['node-b'];
        $this->settleTheFreezeOnTheRuntimeRow();

        $this->coordinator->onBecameLeader();
        $this->coordinator->onDisable('node-c', 'other-agent', 0);

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testAPromotedLeaderStartsAnUnfinishedRoundOverWithEveryFollowerOutstanding(): void
    {
        // Who had already quiesced lived in the dead leader's memory, so the successor claims no
        // knowledge of it. The round usually will not close from here - a follower already frozen
        // drops the repeat - and that is what the watchdog reports as overdue, naming these nodes.
        $this->mesh->followers = ['node-b', 'node-c'];
        $this->withDaemonTruthSource(function (): void {
            Hilos::$rt?->hilosProtectedModeRuntime?->actions->enterActivating(
                new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', null),
                null,
            );
        });

        $this->coordinator->onBecameLeader();

        $this->assertSame(['node-b', 'node-c'], $this->coordinator->pendingNodeIds());
    }

    public function testAPromotedLeaderOwesTheReadyOfAnUnfinishedRound(): void
    {
        // The row does not say whether a close started the round, so an unfinished one is taken
        // for an entry, as it always was.
        $this->mesh->followers = ['node-b'];
        $this->withDaemonTruthSource(function (): void {
            Hilos::$rt?->hilosProtectedModeRuntime?->actions->enterActivating(
                new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', null),
                null,
            );
        });
        $this->coordinator->onBecameLeader();

        $this->coordinator->onQuiesced('node-b');

        $this->assertSame(['enterActive'], $this->executor->calls);
        $this->assertSame([['broadcastSettled', null], ['sendReady', 'node-b']], $this->mesh->calls);
    }

    public function testAPromotedLeaderHandsOutNoSecondReadyForAFreezeAlreadyEstablished(): void
    {
        // A row past activating means the round closed and the initiator has been told to run. A
        // successor that re-collected there would signal ready a second time, in the middle of the
        // operation the first one started.
        $this->mesh->followers = ['node-b'];
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->coordinator->onBecameLeader();

        $this->coordinator->onQuiesced('node-b');

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
        $this->assertSame([], $this->coordinator->pendingNodeIds());
    }

    public function testAPromotedLeaderStopsBeingItsOwnFreezesFollower(): void
    {
        // It was frozen by the leader that is gone, and it leads that freeze now. Left standing, the
        // follower-side marker would outlive the lift - only a lift from the same leader clears it,
        // and a leader sends itself none - and the next freeze would find this node "already
        // frozen" and leave it serving clients through somebody else's restore.
        $this->coordinator->onQuiesce('node-x', new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', null));
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->coordinator->onBecameLeader();
        $this->coordinator->onDisable('node-b', 'backup', 0);
        $this->withDaemonTruthSource(static fn() => Hilos::$rt?->hilosProtectedModeRuntime?->actions->enterInactive());
        $this->coordinator->onLostLeadership();
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onQuiesce('node-y', new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-c', null));
        $this->stopTheRoster();

        $this->assertSame(['enterActivating'], $this->executor->calls);
        $this->assertSame([['sendQuiesced', 'node-y']], $this->mesh->calls);
    }

    public function testAnIdleNodeBecomingLeaderInheritsNothing(): void
    {
        // The ordinary promotion, and the case the rebuild must not disturb: an inactive row means
        // there is no freeze to lead, so a stray disable is still dropped.
        $this->mesh->followers = ['node-b'];

        $this->coordinator->onBecameLeader();
        $this->coordinator->onDisable('node-b', 'backup', 0);

        $this->assertSame([], $this->coordinator->pendingNodeIds());
        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
    }

    /**
     * Mirrors the synchronous direct-window entry and lift while retaining the ordered call log.
     * The coordinator reads entryMode within these calls, before an entry returns or a lift clears it.
     */
    private function recordDirectWindowTransitions(): void
    {
        $this->executor->writeWindow = function (ProtectedModeQuiesceData $freeze, ?string $acceptKey): void {
            $this->withDaemonTruthSource(static function () use ($freeze, $acceptKey): void {
                Hilos::$rt?->hilosProtectedModeRuntime?->actions->enterVerificationWindow($freeze, $acceptKey);
            });
        };
        $this->executor->clearWindow = function (): void {
            $this->withDaemonTruthSource(static function (): void {
                Hilos::$rt?->hilosProtectedModeRuntime?->actions->enterInactive();
            });
        };
    }

    /**
     * Establishes a direct window with one follower and clears the entry's recorded calls.
     */
    private function enterDirectWindowOnLeader(): void
    {
        $this->recordDirectWindowTransitions();
        $this->mesh->followers = ['node-b'];
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->directWindowData());
        $this->coordinator->onQuiesced('node-b');
        $this->executor->calls = [];
        $this->mesh->calls = [];
    }

    /**
     * @param string $operation Operation named by the window
     * @param string $agentType Initiating agent type
     * @param ?int $agentIndex Initiating agent index, or null for a singleton
     * @param ?string $initiatorNodeId Entry node, or null for a malformed cluster request
     * @return ProtectedModeEnableSignalData Direct-window request
     */
    private function directWindowData(
        string $operation = 'manual_maintenance',
        string $agentType = 'index',
        ?int $agentIndex = null,
        ?string $initiatorNodeId = 'node-b',
    ): ProtectedModeEnableSignalData {
        return new ProtectedModeEnableSignalData(
            $operation, 'operator-accept', 'operator-hash', $agentType, $agentIndex, $initiatorNodeId,
            StateProtectedModeRuntime::ENTRY_MODE_VERIFICATION_WINDOW,
        );
    }

    /**
     * Settles the freeze on the runtime row, standing in for the real executor's write.
     *
     * The fake port records transitions instead of writing any, so a case that turns on the phase
     * the row actually reads has to put it there - through the same item actions
     * {@see DaemonProtectedModeExecutor} uses, with the daemon registered as the runtime truth
     * source for exactly the length of the write and dropped after, because the registration is
     * process-wide.
     *
     * @param ?string $acceptKey Initiator accept key to leave on the row, none by default
     * @param ?string $sessionTokenHash Initiator session token hash to leave on the row, none by default
     */
    private function settleTheFreezeOnTheRuntimeRow(?string $acceptKey = null, ?string $sessionTokenHash = null): void
    {
        $view = Hilos::$rt?->hilosProtectedModeRuntime;
        if ($view === null) {
            $this->fail('The protected mode runtime row is not mounted.');
        }

        $this->withDaemonTruthSource(static function () use ($view, $acceptKey, $sessionTokenHash): void {
            $view->actions->enterActivating(
                new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', $sessionTokenHash),
                $acceptKey,
            );
            $view->actions->enterActive();
        });
    }

    /**
     * Leads a freeze for node-b with one follower through its round and its verification window,
     * and closes the window back from node-b.
     *
     * Ends with the close asked for and nothing cleared, so the case reads what the close did.
     *
     * @param ?string $acceptKey Initiator accept key the leader's row carries, none by default
     * @param ?string $sessionTokenHash Initiator session token hash the leader's row carries, none by default
     */
    private function closeTheWindowBackOnTheLeader(?string $acceptKey = null, ?string $sessionTokenHash = null): void
    {
        $this->mesh->followers = ['node-b'];
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->stopTheRoster();
        $this->coordinator->onQuiesced('node-b');
        $this->settleTheFreezeOnTheRuntimeRow($acceptKey, $sessionTokenHash);
        $this->openTheVerificationWindowOnTheRuntimeRow();
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onRefreeze('node-b', 'backup', 0);
    }

    /**
     * Tells the coordinator this node's roster has stopped, with the row on activating as the real
     * executor leaves it for the length of the walk.
     *
     * The fake port writes no phase, and the coordinator only answers a walk that runs on
     * activating - which it reads off the row - so the case has to put the phase there first.
     */
    private function stopTheRoster(): void
    {
        $view = Hilos::$rt?->hilosProtectedModeRuntime;
        if ($view === null) {
            $this->fail('The protected mode runtime row is not mounted.');
        }

        $this->withDaemonTruthSource(static function () use ($view): void {
            $view->actions->enterActivating(new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', null), null);
        });
        $this->coordinator->onRosterStopped();
    }

    /**
     * Opens the verification window on this node's row, the phase a pass may be minted into.
     */
    private function openTheVerificationWindowOnTheRuntimeRow(): void
    {
        $view = Hilos::$rt?->hilosProtectedModeRuntime;
        if ($view === null) {
            $this->fail('The protected mode runtime row is not mounted.');
        }

        $this->withDaemonTruthSource(static fn() => $view->actions->enterVerifying());
    }

    /**
     * Mirrors the direct-window write the recording executor leaves to the test.
     *
     * @param ProtectedModeQuiesceData $window Window descriptor received from the leader
     */
    private function writeDirectWindowOnRuntimeRow(ProtectedModeQuiesceData $window): void
    {
        $this->assertNotNull(Hilos::$rt?->hilosProtectedModeRuntime);
        $this->withDaemonTruthSource(static function () use ($window): void {
            Hilos::$rt?->hilosProtectedModeRuntime?->actions->enterVerificationWindow($window, null);
        });
    }

    /**
     * @param string $passHash Pass hash minted in the open window
     */
    private function mintPassOnRuntimeRow(string $passHash): void
    {
        $view = Hilos::$rt?->hilosProtectedModeRuntime;
        if ($view === null) {
            $this->fail('The protected mode runtime row is not mounted.');
        }

        $this->withDaemonTruthSource(static fn() => $view->actions->issuePass($passHash));
    }

    /**
     * Runs a write with the daemon registered as the runtime truth source, and drops it after.
     *
     * The row refuses a write from anyone else, and the registration is process-wide, so it is
     * held for exactly the length of the write rather than for the length of the test.
     *
     * @param callable(): void $write Write to run as the truth source
     */
    private function withDaemonTruthSource(callable $write): void
    {
        RtTruthSourceRegistry::registerDaemon(StateProtectedModeRuntime::RT_ITEM);
        try {
            $write();
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

    private function circleData(): ProtectedModeCircleSignalData
    {
        return new ProtectedModeCircleSignalData(
            initiatorAgentType: 'backup',
            initiatorAgentIndex: 0,
            namedCount: 1,
            sessionTokenHashes: ['hash-a'],
        );
    }

    private function circleSnapshot(): VerifierCircleSnapshot
    {
        return new VerifierCircleSnapshot(1, ['hash-a']);
    }

    private function progressData(): ProtectedModeProgressSignalData
    {
        return new ProtectedModeProgressSignalData(
            initiatorAgentType: 'backup',
            initiatorAgentIndex: 0,
        );
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
            initiatorSessionTokenHash: null,
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

    /** @var AgentLocation Current location returned for the initiator */
    public AgentLocation $location;

    /** @var array<array{0: string, 1: ?string}> Ordered [method, argument] pairs sent */
    public array $calls = [];

    public ?ProtectedModeQuiesceData $lastQuiesce = null;

    public function __construct()
    {
        $this->location = AgentLocation::onNode('node-b');
    }

    public function followerMasterNodeIds(): array
    {
        return $this->followers;
    }

    public function leaderNodeId(): ?string
    {
        return $this->leader;
    }

    public function locateAgent(string $agentType, ?int $agentIndex): AgentLocation
    {
        return $this->location;
    }

    public function sendEnable(string $leaderNodeId, ProtectedModeEnableSignalData $data): void
    {
        $this->calls[] = ['sendEnable', $leaderNodeId];
    }

    public function sendDisable(string $leaderNodeId, string $agentType, ?int $agentIndex): void
    {
        $this->calls[] = ['sendDisable', $leaderNodeId];
    }

    public function broadcastQuiesce(ProtectedModeQuiesceData $data): void
    {
        $this->lastQuiesce = $data;
        $this->calls[] = ['broadcastQuiesce', $data->operation];
    }

    /**
     * @param ProtectedModeQuiesceData $data Window descriptor to record
     */
    public function broadcastWindow(ProtectedModeQuiesceData $data): void
    {
        $this->calls[] = ['broadcastWindow', $data->operation];
    }

    public function sendReady(string $initiatorNodeId): void
    {
        $this->calls[] = ['sendReady', $initiatorNodeId];
    }

    public function sendRefused(string $initiatorNodeId, string $reason): void
    {
        $this->calls[] = ['sendRefused', $initiatorNodeId, $reason];
    }

    public function broadcastLift(): void
    {
        $this->calls[] = ['broadcastLift', null];
    }

    public function sendQuiesced(string $leaderNodeId): void
    {
        $this->calls[] = ['sendQuiesced', $leaderNodeId];
    }

    public function sendVerify(string $leaderNodeId, string $agentType, ?int $agentIndex): void
    {
        $this->calls[] = ['sendVerify', $leaderNodeId];
    }

    public function sendProgress(string $leaderNodeId, string $agentType, ?int $agentIndex): void
    {
        $this->calls[] = ['sendProgress', $leaderNodeId];
    }

    public function broadcastVerify(string $agentType, ?int $agentIndex): void
    {
        $this->calls[] = ['broadcastVerify', null];
    }

    public function sendPass(string $leaderNodeId, string $agentType, ?int $agentIndex, string $passHash): void
    {
        $this->calls[] = ['sendPass', $leaderNodeId];
    }

    public function broadcastPass(string $agentType, ?int $agentIndex, string $passHash): void
    {
        $this->calls[] = ['broadcastPass', $passHash];
    }

    public function sendAdmit(string $leaderNodeId, string $passHash, string $sessionTokenHash): void
    {
        $this->calls[] = ['sendAdmit', $leaderNodeId];
    }

    public function broadcastAdmit(string $passHash, string $sessionTokenHash): void
    {
        $this->calls[] = ['broadcastAdmit', $passHash];
    }

    public function sendCircle(string $leaderNodeId, string $agentType, ?int $agentIndex, VerifierCircleSnapshot $snapshot): void
    {
        $this->calls[] = ['sendCircle', $leaderNodeId];
    }

    public function broadcastCircle(string $agentType, ?int $agentIndex, VerifierCircleSnapshot $snapshot): void
    {
        $this->calls[] = ['broadcastCircle', (string)$snapshot->namedCount];
    }

    public function sendRefreeze(string $leaderNodeId, string $agentType, ?int $agentIndex): void
    {
        $this->calls[] = ['sendRefreeze', $leaderNodeId];
    }

    public function broadcastSettled(): void
    {
        $this->calls[] = ['broadcastSettled', null];
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

    /** @var ?string Session token hash passed to the most recent enterActivating call */
    public ?string $activatingSessionTokenHash = null;

    /** @var ?string Accept key passed to the most recent direct window call */
    public ?string $windowAcceptKey = 'not-called';

    /** @var ?Closure(ProtectedModeQuiesceData, ?string): void Optional synchronous window write */
    public ?Closure $writeWindow = null;

    /** @var ?Closure(): void Optional synchronous inactive write */
    public ?Closure $clearWindow = null;

    public ?ProtectedModeQuiesceData $freeze = null;

    public function enterActivating(
        ProtectedModeQuiesceData $freeze,
        ?string $initiatorAcceptKey,
    ): void {
        $this->calls[] = 'enterActivating';
        $this->activatingAcceptKey = $initiatorAcceptKey;
        $this->activatingSessionTokenHash = $freeze->initiatorSessionTokenHash;
        $this->freeze = $freeze;
    }

    /**
     * @param ProtectedModeQuiesceData $freeze Window descriptor to record
     * @param ?string $initiatorAcceptKey Initiating connection, or null on a follower
     */
    public function enterVerificationWindow(
        ProtectedModeQuiesceData $freeze,
        ?string $initiatorAcceptKey,
    ): void {
        $this->calls[] = 'enterVerificationWindow';
        $this->windowAcceptKey = $initiatorAcceptKey;
        $this->freeze = $freeze;
        $this->writeWindow?->__invoke($freeze, $initiatorAcceptKey);
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

    public function finishVerifying(): void
    {
        $this->calls[] = 'finishVerifying';
    }

    public function announcePassIssued(): void
    {
        $this->calls[] = 'announcePassIssued';
    }

    public function admitVerifier(string $sessionTokenHash): void
    {
        $this->calls[] = 'admitVerifier';
    }

    public function enterInactive(): void
    {
        $this->calls[] = 'enterInactive';
        $this->clearWindow?->__invoke();
    }

    public function finishLift(): void
    {
        $this->calls[] = 'finishLift';
    }

    public function notifyInitiatorReady(): void
    {
        $this->calls[] = 'notifyInitiatorReady';
    }
}

/**
 * Recording fake of the initiator relay port: captures ready and refusal notices.
 */
final class FakeClusterInitiatorRelay implements ProtectedModeInitiatorRelay
{
    /** @var list<array{agentType: string, agentIndex: ?string}> */
    public array $readyCalls = [];

    /** @var list<array{agentType: string, agentIndex: ?string, reason: string}> */
    public array $refusedCalls = [];

    public function deliverProtectedModeReady(string $agentType, ?string $agentIndex): void
    {
        $this->readyCalls[] = [
            'agentType' => $agentType,
            'agentIndex' => $agentIndex,
        ];
    }

    public function deliverProtectedModeRefused(string $agentType, ?string $agentIndex, string $reason): void
    {
        $this->refusedCalls[] = [
            'agentType' => $agentType,
            'agentIndex' => $agentIndex,
            'reason' => $reason,
        ];
    }
}
