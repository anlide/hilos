<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\ProtectedMode;

use Hilos\Cluster\ClusterContext;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Hilos;
use Hilos\ProtectedMode\DTO\ProtectedModeCircleSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeDisableSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeEnableSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModePassSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeProgressSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeQuiesceData;
use Hilos\ProtectedMode\DTO\ProtectedModeRefreezeSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeVerifySignalData;
use Hilos\ProtectedMode\DaemonProtectedModeExecutor;
use Hilos\ProtectedMode\ProtectedModeExecutor;
use Hilos\ProtectedMode\ProtectedModeInitiatorRelay;
use Hilos\ProtectedMode\ProtectedModeRefusalCopy;
use Hilos\ProtectedMode\StandaloneProtectedMode;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the single-node freeze.
 *
 * The machine is driven through the request seam an initiator's daemon calls and observed through
 * a recording fake of the local-node port, so entry, refusal and release are pinned without a
 * daemon: entering asks for the roster to stop and tells the initiator to go once it has, a repeat
 * request never re-enters so the stopped-agent roster is not re-rolled - though the initiator of a
 * freeze that already stands is told ready again rather than refused - only the recorded initiator
 * may release, a project that mounts no runtime row never gets a ready, and a switch built over a
 * freeze restored from disk adopts it, so the initiator the row records drives it again.
 */
final class StandaloneProtectedModeTest extends TestCase
{
    private const string INITIATOR_TYPE = 'backup';

    private const int INITIATOR_INDEX = 2;

    private FakeStandaloneExecutor $executor;

    private StandaloneProtectedMode $mode;

    private FakeStandaloneInitiatorRelay $relay;

    protected function setUp(): void
    {
        $this->executor = new FakeStandaloneExecutor();
        $this->mode = new StandaloneProtectedMode($this->executor);
        $this->relay = new FakeStandaloneInitiatorRelay();
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

    public function testEnteringFreezesTheNodeAndWaitsForTheRosterBeforeSignallingTheInitiator(): void
    {
        $this->mode->requestEnable($this->enableData());

        // The roster stops over several master passes, so the node is not frozen yet: a ready
        // here would start the operation over agents that are still serving (HIL-1012).
        $this->assertSame(['enterActivating'], $this->executor->calls);
        $this->assertSame('accept-9', $this->executor->activatingAcceptKey);
        $this->assertSame(self::INITIATOR_TYPE, $this->executor->freeze?->initiatorAgentType);
        $this->assertSame(self::INITIATOR_INDEX, $this->executor->freeze?->initiatorAgentIndex);
        // Nothing to name: the freeze never leaves this node, so it carries no node id.
        $this->assertNull($this->executor->freeze?->initiatorNodeId);
    }

    public function testDirectEntrySignalsReadyWithoutWalkingTheRoster(): void
    {
        $this->mode->requestEnable($this->enableData(entryMode: StateProtectedModeRuntime::ENTRY_MODE_VERIFICATION_WINDOW));

        $this->assertSame(['enterVerificationWindow', 'notifyInitiatorReady'], $this->executor->calls);
    }

    public function testDirectWindowFinishesOnFirstCircleIncludingEmptyPhotograph(): void
    {
        $this->enterDirectWindowOnTheRuntimeRow();
        $this->executor->calls = [];

        $this->withDaemonTruthSource(function (): void {
            $this->mode->requestCircle($this->circleData(0, []));
            $this->mode->requestCircle($this->circleData(1, ['late-hash']));
        });

        $this->assertSame(['finishVerifying'], $this->executor->calls);
        $this->assertSame([], Hilos::$rt?->hilosProtectedModeRuntime?->circleSessionTokenHashes);
        $this->assertSame(0, Hilos::$rt?->hilosProtectedModeRuntime?->circleNamedCount);
    }

    public function testDirectWindowRepeatAnswersReadyAndCrossEntryRefuses(): void
    {
        $this->enterDirectWindowOnTheRuntimeRow();
        $this->executor->calls = [];

        $this->mode->requestEnable($this->enableData(entryMode: StateProtectedModeRuntime::ENTRY_MODE_VERIFICATION_WINDOW));
        $this->mode->requestEnable($this->enableData());
        $this->mode->requestEnable(new ProtectedModeEnableSignalData(
            'other-operation', 'accept-9', null, self::INITIATOR_TYPE, self::INITIATOR_INDEX, null,
            StateProtectedModeRuntime::ENTRY_MODE_VERIFICATION_WINDOW,
        ));
        $this->mode->requestEnable($this->enableData('chat', null, StateProtectedModeRuntime::ENTRY_MODE_VERIFICATION_WINDOW));

        $this->assertSame(['notifyInitiatorReady'], $this->executor->calls);
        $this->assertSame(3, count($this->relay->refusedCalls));
        $this->assertSame(ProtectedModeRefusalCopy::ANOTHER_OPERATION, $this->relay->refusedCalls[0]['reason']);
        $this->assertSame(ProtectedModeRefusalCopy::ANOTHER_OPERATION, $this->relay->refusedCalls[1]['reason']);
        $this->assertSame(ProtectedModeRefusalCopy::FOREIGN_FREEZE, $this->relay->refusedCalls[2]['reason']);
    }

    public function testDirectWindowRejectsRefreezeAndLiftsImmediately(): void
    {
        $this->enterDirectWindowOnTheRuntimeRow();
        $this->executor->calls = [];

        $this->mode->requestRefreeze(new ProtectedModeRefreezeSignalData(self::INITIATOR_TYPE, self::INITIATOR_INDEX));
        $this->mode->requestDisable($this->disableData(self::INITIATOR_TYPE, self::INITIATOR_INDEX));

        $this->assertSame(['enterInactive', 'finishLift'], $this->executor->calls);
    }

    public function testTheStoppedRosterMarksTheFreezeActiveAndSignalsTheInitiator(): void
    {
        $this->mode->requestEnable($this->enableData());
        $this->recordInitiatorOnTheRuntimeRow(self::INITIATOR_TYPE, self::INITIATOR_INDEX, activate: false);
        $this->executor->calls = [];

        $this->mode->onRosterStopped();

        $this->assertSame(['enterActive', 'notifyInitiatorReady'], $this->executor->calls);
    }

    public function testTheRosterStoppedByClosingTheWindowBackAnswersNobody(): void
    {
        // The close walks on activating like an entry (HIL-1128) and writes active at its end, but
        // its initiator was told ready when the freeze first took hold - a second ready would
        // restart the operation behind the operator's back.
        $this->closeTheWindowBack();
        $this->recordInitiatorOnTheRuntimeRow(self::INITIATOR_TYPE, self::INITIATOR_INDEX, activate: false);
        $this->executor->calls = [];

        $this->mode->onRosterStopped();

        $this->assertSame(['enterActive'], $this->executor->calls);
    }

    public function testAnEnableDuringTheCloseWalkIsRefusedAsAnotherOperation(): void
    {
        // The row says activating while the close walks, so the same refusal an enable meets
        // during the first entry - not a ready over agents still running (HIL-1128).
        $this->closeTheWindowBack();
        $this->recordInitiatorOnTheRuntimeRow(self::INITIATOR_TYPE, self::INITIATOR_INDEX, activate: false);
        $this->executor->calls = [];
        $this->relay->refusedCalls = [];

        $this->mode->requestEnable($this->enableData());

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([
            [
                'agentType' => self::INITIATOR_TYPE,
                'agentIndex' => (string)self::INITIATOR_INDEX,
                'reason' => ProtectedModeRefusalCopy::ANOTHER_OPERATION,
            ],
        ], $this->relay->refusedCalls);
    }

    public function testAnEnableAfterTheCloseWalkIsToldReady(): void
    {
        $this->closeTheWindowBack();
        $this->recordInitiatorOnTheRuntimeRow(self::INITIATOR_TYPE, self::INITIATOR_INDEX, activate: false);
        $this->mode->onRosterStopped();
        $this->recordInitiatorOnTheRuntimeRow(self::INITIATOR_TYPE, self::INITIATOR_INDEX);
        $this->executor->calls = [];

        $this->mode->requestEnable($this->enableData());

        $this->assertSame(['notifyInitiatorReady'], $this->executor->calls);
    }

    public function testAReEntryAfterACloseOwesTheReadyAgain(): void
    {
        // The close cleared what the walk owes; an entry from the next window has to set it again,
        // or its initiator would wait for a ready that never comes.
        $this->closeTheWindowBack();
        $this->recordInitiatorOnTheRuntimeRow(self::INITIATOR_TYPE, self::INITIATOR_INDEX, activate: false);
        $this->mode->onRosterStopped();
        $this->recordInitiatorOnTheRuntimeRow(self::INITIATOR_TYPE, self::INITIATOR_INDEX);
        $this->enterVerifyingOnTheRuntimeRow();
        $this->mode->requestEnable($this->enableData());
        $this->recordInitiatorOnTheRuntimeRow(self::INITIATOR_TYPE, self::INITIATOR_INDEX, activate: false);
        $this->executor->calls = [];

        $this->mode->onRosterStopped();

        $this->assertSame(['enterActive', 'notifyInitiatorReady'], $this->executor->calls);
    }

    public function testARosterStoppedUnderNoFreezeAnswersNobody(): void
    {
        $this->mode->onRosterStopped();

        $this->assertSame([], $this->executor->calls);
    }

    public function testTheRosterBackInTheWindowFinishesTheWindow(): void
    {
        $this->mode->requestEnable($this->enableData());
        $this->recordInitiatorOnTheRuntimeRow(self::INITIATOR_TYPE, self::INITIATOR_INDEX);
        $this->enterVerifyingOnTheRuntimeRow();
        $this->executor->calls = [];

        $this->mode->onRosterResumed();

        $this->assertSame(['finishVerifying'], $this->executor->calls);
    }

    public function testTheRosterBackAfterTheReleaseFinishesTheLift(): void
    {
        // A fresh row reads inactive, which is what the real executor writes before it asks for
        // the roster back.
        $this->mode->onRosterResumed();

        $this->assertSame(['finishLift'], $this->executor->calls);
    }

    public function testRepeatedEnableBeforeTheFreezeSettlesIsDropped(): void
    {
        // The fake executor writes no row, so the freeze is still on its way in - and an entry
        // run twice re-rolls the stopped-agent roster the release resumes against.
        $this->mode->requestEnable($this->enableData());
        $this->recordInitiatorOnTheRuntimeRow(self::INITIATOR_TYPE, self::INITIATOR_INDEX, activate: false);
        $this->executor->calls = [];
        $this->relay->refusedCalls = [];

        $this->mode->requestEnable($this->enableData());

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([
            [
                'agentType' => self::INITIATOR_TYPE,
                'agentIndex' => (string)self::INITIATOR_INDEX,
                'reason' => ProtectedModeRefusalCopy::ANOTHER_OPERATION,
            ],
        ], $this->relay->refusedCalls);
    }

    public function testTheInitiatorOfASettledFreezeIsToldReadyAgainInsteadOfRefused(): void
    {
        // What an operator does after closing the verification window: the node stays frozen on
        // active precisely so another restore can run, so the enable that restore raises has to be
        // answered rather than dropped as a duplicate. Nothing is re-entered - the node is already
        // quiesced, which is the whole of what a ready asserts.
        $this->mode->requestEnable($this->enableData());
        $this->recordInitiatorOnTheRuntimeRow(self::INITIATOR_TYPE, self::INITIATOR_INDEX);
        $this->executor->calls = [];

        $this->mode->requestEnable($this->enableData());

        $this->assertSame(['notifyInitiatorReady'], $this->executor->calls);
    }

    public function testEnableFromAnotherAgentUnderASettledFreezeIsDropped(): void
    {
        $this->mode->requestEnable($this->enableData());
        $this->recordInitiatorOnTheRuntimeRow(self::INITIATOR_TYPE, self::INITIATOR_INDEX);
        $this->executor->calls = [];
        $this->relay->refusedCalls = [];

        $this->mode->requestEnable($this->enableData('chat', null));

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([
            [
                'agentType' => 'chat',
                'agentIndex' => null,
                'reason' => ProtectedModeRefusalCopy::FOREIGN_FREEZE,
            ],
        ], $this->relay->refusedCalls);
    }

    public function testEnableInsideTheVerificationWindowEntersAgainAndWaitsForTheRoster(): void
    {
        $this->mode->requestEnable($this->enableData());
        $this->recordInitiatorOnTheRuntimeRow(self::INITIATOR_TYPE, self::INITIATOR_INDEX);
        $this->enterVerifyingOnTheRuntimeRow();
        $this->executor->calls = [];

        $enable = new ProtectedModeEnableSignalData(
            operation: 'restore',
            initiatorAcceptKey: 'accept-second',
            initiatorSessionTokenHash: 'session-hash-second',
            initiatorAgentType: self::INITIATOR_TYPE,
            initiatorAgentIndex: self::INITIATOR_INDEX,
            initiatorNodeId: null,
        );
        $this->mode->requestEnable($enable);

        $this->assertSame(['enterActivating'], $this->executor->calls);
        $this->assertSame('accept-second', $this->executor->activatingAcceptKey);
        $this->assertSame('session-hash-second', $this->executor->activatingSessionTokenHash);
        $this->assertSame('restore', $this->executor->freeze?->operation);
    }

    public function testTheRosterStoppedAfterEnteringAgainSignalsTheInitiatorReady(): void
    {
        $this->mode->requestEnable($this->enableData());
        $this->recordInitiatorOnTheRuntimeRow(self::INITIATOR_TYPE, self::INITIATOR_INDEX);
        $this->enterVerifyingOnTheRuntimeRow();
        $this->mode->requestEnable(new ProtectedModeEnableSignalData(
            operation: 'restore',
            initiatorAcceptKey: 'accept-second',
            initiatorSessionTokenHash: 'session-hash-second',
            initiatorAgentType: self::INITIATOR_TYPE,
            initiatorAgentIndex: self::INITIATOR_INDEX,
            initiatorNodeId: null,
        ));
        $this->recordInitiatorOnTheRuntimeRow(self::INITIATOR_TYPE, self::INITIATOR_INDEX, activate: false);
        $this->executor->calls = [];

        $this->mode->onRosterStopped();

        $this->assertSame(['enterActive', 'notifyInitiatorReady'], $this->executor->calls);
    }

    public function testAReleaseDuringTheRepeatWalkLeavesTheReadyUnsent(): void
    {
        $this->mode->requestEnable($this->enableData());
        $this->recordInitiatorOnTheRuntimeRow(self::INITIATOR_TYPE, self::INITIATOR_INDEX);
        $this->enterVerifyingOnTheRuntimeRow();
        $this->executor->calls = [];

        $this->mode->requestEnable(new ProtectedModeEnableSignalData(
            operation: 'restore',
            initiatorAcceptKey: 'accept-second',
            initiatorSessionTokenHash: 'session-hash-second',
            initiatorAgentType: self::INITIATOR_TYPE,
            initiatorAgentIndex: self::INITIATOR_INDEX,
            initiatorNodeId: null,
        ));
        $this->mode->requestDisable($this->disableData(self::INITIATOR_TYPE, self::INITIATOR_INDEX));
        $this->mode->onRosterStopped();

        $this->assertSame(['enterActivating', 'enterDeactivating', 'enterInactive'], $this->executor->calls);
    }

    public function testEnableInsideTheVerificationWindowFromAnotherAgentIsRefused(): void
    {
        $this->mode->requestEnable($this->enableData());
        $this->recordInitiatorOnTheRuntimeRow(self::INITIATOR_TYPE, self::INITIATOR_INDEX);
        $this->enterVerifyingOnTheRuntimeRow();
        $this->executor->calls = [];
        $this->relay->refusedCalls = [];

        $this->mode->requestEnable($this->enableData('chat', null));

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([
            [
                'agentType' => 'chat',
                'agentIndex' => null,
                'reason' => ProtectedModeRefusalCopy::FOREIGN_FREEZE,
            ],
        ], $this->relay->refusedCalls);
    }

    public function testInitiatorReleasesTheFreeze(): void
    {
        $this->mode->requestEnable($this->enableData());
        $this->recordInitiatorOnTheRuntimeRow(self::INITIATOR_TYPE, self::INITIATOR_INDEX);
        $this->executor->calls = [];

        $this->mode->requestDisable($this->disableData(self::INITIATOR_TYPE, self::INITIATOR_INDEX));

        $this->assertSame(['enterDeactivating', 'enterInactive'], $this->executor->calls);
    }

    public function testReleaseFromAnotherAgentIsDropped(): void
    {
        $this->mode->requestEnable($this->enableData());
        $this->recordInitiatorOnTheRuntimeRow(self::INITIATOR_TYPE, self::INITIATOR_INDEX);
        $this->executor->calls = [];

        $this->mode->requestDisable($this->disableData('chat', null));

        $this->assertSame([], $this->executor->calls);
    }

    public function testReleaseWithoutAnActiveFreezeIsDropped(): void
    {
        $this->mode->requestDisable($this->disableData(self::INITIATOR_TYPE, self::INITIATOR_INDEX));

        $this->assertSame([], $this->executor->calls);
    }

    public function testTheInitiatorOpensTheVerificationWindow(): void
    {
        $this->mode->requestEnable($this->enableData());
        $this->recordInitiatorOnTheRuntimeRow(self::INITIATOR_TYPE, self::INITIATOR_INDEX);
        $this->executor->calls = [];

        $this->mode->requestVerify(new ProtectedModeVerifySignalData(self::INITIATOR_TYPE, self::INITIATOR_INDEX));

        $this->assertSame(['enterVerifying'], $this->executor->calls);
    }

    public function testVerifyFromAnotherAgentIsDropped(): void
    {
        $this->mode->requestEnable($this->enableData());
        $this->recordInitiatorOnTheRuntimeRow(self::INITIATOR_TYPE, self::INITIATOR_INDEX);
        $this->executor->calls = [];

        $this->mode->requestVerify(new ProtectedModeVerifySignalData('chat', null));

        $this->assertSame([], $this->executor->calls);
    }

    public function testVerifyFromTheWrongPhaseIsDropped(): void
    {
        // The row is left at activating, which is where a freeze sits before every node has
        // quiesced: there is no finished operation to verify yet.
        $this->mode->requestEnable($this->enableData());
        $this->recordInitiatorOnTheRuntimeRow(self::INITIATOR_TYPE, self::INITIATOR_INDEX, activate: false);
        $this->executor->calls = [];

        $this->mode->requestVerify(new ProtectedModeVerifySignalData(self::INITIATOR_TYPE, self::INITIATOR_INDEX));

        $this->assertSame([], $this->executor->calls);
    }

    public function testAPassIsRecordedOnlyInsideTheWindow(): void
    {
        $this->mode->requestEnable($this->enableData());
        $this->recordInitiatorOnTheRuntimeRow(self::INITIATOR_TYPE, self::INITIATOR_INDEX);
        $pass = new ProtectedModePassSignalData(self::INITIATOR_TYPE, self::INITIATOR_INDEX, 'hash-a');

        // Active, not verifying: nobody may be let in yet.
        $this->mode->requestPass($pass);
        $this->assertSame([], Hilos::$rt?->hilosProtectedModeRuntime?->passHashes);

        $this->enterVerifyingOnTheRuntimeRow();
        $this->withDaemonTruthSource(fn() => $this->mode->requestPass($pass));

        $this->assertSame(['hash-a'], Hilos::$rt?->hilosProtectedModeRuntime?->passHashes);
    }

    public function testCodeAdmissionUsesTheLocalExecutor(): void
    {
        $this->mode->requestAdmit('pass-hash', 'verifier-hash');

        $this->assertSame(['admitVerifier'], $this->executor->calls);
    }

    public function testOnlyTheFirstPassIsAnnouncedToTheFrozenBrowsers(): void
    {
        // Zero-to-one is the only step that changes anything on a stub: it swaps the sentence
        // saying nothing has been minted for the code field. A second mint would broadcast to
        // every frozen browser to tell them what they are already showing.
        $this->mode->requestEnable($this->enableData());
        $this->recordInitiatorOnTheRuntimeRow(self::INITIATOR_TYPE, self::INITIATOR_INDEX);
        $this->enterVerifyingOnTheRuntimeRow();
        $this->executor->calls = [];

        $this->withDaemonTruthSource(function (): void {
            $this->mode->requestPass(new ProtectedModePassSignalData(self::INITIATOR_TYPE, self::INITIATOR_INDEX, 'hash-a'));
            $this->mode->requestPass(new ProtectedModePassSignalData(self::INITIATOR_TYPE, self::INITIATOR_INDEX, 'hash-b'));
        });

        $this->assertSame(['hash-a', 'hash-b'], Hilos::$rt?->hilosProtectedModeRuntime?->passHashes);
        $this->assertSame(['announcePassIssued'], $this->executor->calls);
    }

    public function testAPassRefusedOutsideTheWindowAnnouncesNothing(): void
    {
        $this->mode->requestEnable($this->enableData());
        $this->recordInitiatorOnTheRuntimeRow(self::INITIATOR_TYPE, self::INITIATOR_INDEX);
        $this->executor->calls = [];

        $this->mode->requestPass(new ProtectedModePassSignalData(self::INITIATOR_TYPE, self::INITIATOR_INDEX, 'hash-a'));

        $this->assertSame([], $this->executor->calls);
    }

    public function testTheCircleIsWrittenWholeOnTheSettledFreeze(): void
    {
        $this->mode->requestEnable($this->enableData());
        $this->recordInitiatorOnTheRuntimeRow(self::INITIATOR_TYPE, self::INITIATOR_INDEX);

        $this->withDaemonTruthSource(fn() => $this->mode->requestCircle($this->circleData(2, ['hash-a', 'hash-b'])));

        $this->assertSame(['hash-a', 'hash-b'], Hilos::$rt?->hilosProtectedModeRuntime?->circleSessionTokenHashes);
        $this->assertSame(2, Hilos::$rt?->hilosProtectedModeRuntime?->circleNamedCount);
    }

    public function testASecondPhotographOfTheCircleReplacesTheFirst(): void
    {
        // The write is a list rather than an entry, so a relay that arrives twice cannot double
        // the hall - which is what makes the frame safe to resend at all.
        $this->mode->requestEnable($this->enableData());
        $this->recordInitiatorOnTheRuntimeRow(self::INITIATOR_TYPE, self::INITIATOR_INDEX);

        $this->withDaemonTruthSource(function (): void {
            $this->mode->requestCircle($this->circleData(2, ['hash-a', 'hash-b']));
            $this->mode->requestCircle($this->circleData(2, ['hash-a', 'hash-b']));
        });

        $this->assertSame(['hash-a', 'hash-b'], Hilos::$rt?->hilosProtectedModeRuntime?->circleSessionTokenHashes);
    }

    public function testACircleFromAnotherAgentIsDropped(): void
    {
        // The payload names browsers the window will let in unasked, so an agent that did not
        // freeze the node could otherwise walk anybody it liked into a restored system.
        $this->mode->requestEnable($this->enableData());
        $this->recordInitiatorOnTheRuntimeRow(self::INITIATOR_TYPE, self::INITIATOR_INDEX);

        $this->mode->requestCircle($this->circleData(1, ['hash-a'], 'chat', null));

        $this->assertSame([], Hilos::$rt?->hilosProtectedModeRuntime?->circleSessionTokenHashes);
        $this->assertSame(0, Hilos::$rt?->hilosProtectedModeRuntime?->circleNamedCount);
    }

    public function testACircleArrivingInsideTheWindowIsDropped(): void
    {
        // The photograph belongs to the freeze, taken before the database was replaced; one
        // offered later was read from the restored database and names whoever it now holds.
        $this->mode->requestEnable($this->enableData());
        $this->recordInitiatorOnTheRuntimeRow(self::INITIATOR_TYPE, self::INITIATOR_INDEX);
        $this->enterVerifyingOnTheRuntimeRow();

        $this->mode->requestCircle($this->circleData(1, ['hash-a']));

        $this->assertSame([], Hilos::$rt?->hilosProtectedModeRuntime?->circleSessionTokenHashes);
    }

    public function testTheInitiatorStampsTheProgressMarkOnTheRow(): void
    {
        $this->mode->requestEnable($this->enableData());
        $this->recordInitiatorOnTheRuntimeRow(self::INITIATOR_TYPE, self::INITIATOR_INDEX);
        $this->executor->calls = [];
        $this->assertNull(Hilos::$rt?->hilosProtectedModeRuntime?->progressAt);

        $before = time();
        $this->withDaemonTruthSource(fn() => $this->mode->requestProgress($this->progressData(
            self::INITIATOR_TYPE,
            self::INITIATOR_INDEX,
        )));

        $stamped = Hilos::$rt?->hilosProtectedModeRuntime?->progressAt;
        $this->assertNotNull($stamped);
        $this->assertGreaterThanOrEqual($before, $stamped);
        // The mark moves nothing: it is the one request that reports rather than asks.
        $this->assertSame([], $this->executor->calls);
    }

    public function testProgressFromAnotherAgentIsDropped(): void
    {
        // An agent that did not freeze the node could otherwise keep a hung operation looking
        // alive for as long as it liked, which is the one thing the mark exists to expose.
        $this->mode->requestEnable($this->enableData());
        $this->recordInitiatorOnTheRuntimeRow(self::INITIATOR_TYPE, self::INITIATOR_INDEX);

        $this->withDaemonTruthSource(fn() => $this->mode->requestProgress($this->progressData('chat', null)));

        $this->assertNull(Hilos::$rt?->hilosProtectedModeRuntime?->progressAt);
    }

    public function testProgressUnderNoFreezeIsDroppedWithoutWriting(): void
    {
        // A restore marks its acceptance before the freeze exists and its outcome after the
        // freeze has lifted; both are honest reports with nowhere to land, and neither is an
        // error. Run without the truth source on purpose: reaching the row here would throw.
        $this->mode->requestProgress($this->progressData(self::INITIATOR_TYPE, self::INITIATOR_INDEX));

        $this->assertNull(Hilos::$rt?->hilosProtectedModeRuntime?->progressAt);
        $this->assertSame([], $this->executor->calls);
    }

    public function testOpeningTheVerificationWindowCountsAsProgress(): void
    {
        // Otherwise the window would be reported stuck the moment it opened, for the silence of
        // the operation that just ended. It gets a full threshold of its own instead.
        $this->mode->requestEnable($this->enableData());
        $this->recordInitiatorOnTheRuntimeRow(self::INITIATOR_TYPE, self::INITIATOR_INDEX);

        $before = time();
        $this->enterVerifyingOnTheRuntimeRow();

        $stamped = Hilos::$rt?->hilosProtectedModeRuntime?->progressAt;
        $this->assertNotNull($stamped);
        $this->assertGreaterThanOrEqual($before, $stamped);
    }

    public function testTheInitiatorClosesTheWindowBackToAFullFreeze(): void
    {
        // The close is an entry (HIL-1128): the same freeze, and the accept key and the session
        // hash the row already carries, so the next window lets the same operator in.
        $this->mode->requestEnable($this->enableData());
        $this->recordInitiatorOnTheRuntimeRow(
            self::INITIATOR_TYPE,
            self::INITIATOR_INDEX,
            acceptKey: 'accept-on-row',
            sessionTokenHash: 'session-hash-on-row',
        );
        $this->enterVerifyingOnTheRuntimeRow();
        $this->executor->calls = [];

        $this->mode->requestRefreeze(new ProtectedModeRefreezeSignalData(self::INITIATOR_TYPE, self::INITIATOR_INDEX));

        $this->assertSame(['enterActivating'], $this->executor->calls);
        $this->assertSame('restore', $this->executor->freeze?->operation);
        $this->assertSame(self::INITIATOR_TYPE, $this->executor->freeze?->initiatorAgentType);
        $this->assertSame('accept-on-row', $this->executor->activatingAcceptKey);
        $this->assertSame('session-hash-on-row', $this->executor->activatingSessionTokenHash);
    }

    public function testRefreezeOutsideTheWindowIsDropped(): void
    {
        $this->mode->requestEnable($this->enableData());
        $this->recordInitiatorOnTheRuntimeRow(self::INITIATOR_TYPE, self::INITIATOR_INDEX);
        $this->executor->calls = [];

        $this->mode->requestRefreeze(new ProtectedModeRefreezeSignalData(self::INITIATOR_TYPE, self::INITIATOR_INDEX));

        $this->assertSame([], $this->executor->calls);
    }

    public function testWithoutAMountedRuntimeRowTheModeNeitherEntersNorReportsReady(): void
    {
        // Fail-closed: the initiator waits for ready before it destroys anything, so refusing to
        // enter ends that wait with a reason instead of letting it run over a live system.
        Hilos::$rt = null;
        $this->relay->refusedCalls = [];

        $this->mode->requestEnable($this->enableData());

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([
            [
                'agentType' => self::INITIATOR_TYPE,
                'agentIndex' => (string)self::INITIATOR_INDEX,
                'reason' => ProtectedModeRefusalCopy::NO_RUNTIME_ROW,
            ],
        ], $this->relay->refusedCalls);
    }

    public function testARestoredFreezeIsReleasedByItsRecordedInitiator(): void
    {
        // The live failure (HIL-1510): the switch that held the freeze died with the master, and its
        // successor dropped the release of the very agent the row names - nothing but a hand on the
        // freeze file could open the node.
        $this->restartUnder(StateProtectedModeRuntime::PHASE_ACTIVE);

        $this->mode->requestDisable($this->disableData(self::INITIATOR_TYPE, self::INITIATOR_INDEX));

        $this->assertSame(['enterDeactivating', 'enterInactive'], $this->executor->calls);
    }

    public function testARestoredFreezeStillRefusesAnotherAgent(): void
    {
        // The adoption rebuilds the identity from the row, it does not waive it: a stranger cannot
        // open, close or mint into a freeze it did not start, restart or no restart.
        $this->restartUnder(StateProtectedModeRuntime::PHASE_ACTIVE);

        $this->mode->requestDisable($this->disableData('chat', null));
        $this->mode->requestVerify(new ProtectedModeVerifySignalData('chat', null));
        $this->enterVerifyingOnTheRuntimeRow();
        $this->withDaemonTruthSource(fn() => $this->mode->requestPass(new ProtectedModePassSignalData('chat', null, 'hash-a')));

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], Hilos::$rt?->hilosProtectedModeRuntime?->passHashes);
    }

    public function testTheRecordedInitiatorOpensAndMintsIntoARestoredFreeze(): void
    {
        $this->restartUnder(StateProtectedModeRuntime::PHASE_ACTIVE);

        $this->mode->requestVerify(new ProtectedModeVerifySignalData(self::INITIATOR_TYPE, self::INITIATOR_INDEX));
        $this->assertSame(['enterVerifying'], $this->executor->calls);

        $this->enterVerifyingOnTheRuntimeRow();
        $this->executor->calls = [];
        $this->withDaemonTruthSource(fn() => $this->mode->requestPass(
            new ProtectedModePassSignalData(self::INITIATOR_TYPE, self::INITIATOR_INDEX, 'hash-a'),
        ));

        $this->assertSame(['hash-a'], Hilos::$rt?->hilosProtectedModeRuntime?->passHashes);
        $this->assertSame(['announcePassIssued'], $this->executor->calls);
    }

    public function testAnEnableOverARestoredFreezeIsAnsweredAsOverAStandingOne(): void
    {
        // The row is not overwritten by a fresh entry: its initiator is told ready, as over any
        // freeze that stands on active, and anyone else is refused with the reason that names it.
        $this->restartUnder(StateProtectedModeRuntime::PHASE_ACTIVE);

        $this->mode->requestEnable($this->enableData());
        $this->mode->requestEnable($this->enableData('chat', null));

        $this->assertSame(['notifyInitiatorReady'], $this->executor->calls);
        $this->assertSame([
            [
                'agentType' => 'chat',
                'agentIndex' => null,
                'reason' => ProtectedModeRefusalCopy::FOREIGN_FREEZE,
            ],
        ], $this->relay->refusedCalls);
    }

    public function testARestoredDirectWindowTakesNoSecondCircleAndLiftsAtOnce(): void
    {
        // The restart burned the circle the window admitted, and its one photograph counts as taken:
        // a late one would admit nobody new and re-send window frames to browsers that read the
        // state on their handshake anyway.
        $this->restartUnder(
            StateProtectedModeRuntime::PHASE_VERIFYING,
            StateProtectedModeRuntime::ENTRY_MODE_VERIFICATION_WINDOW,
        );

        $this->withDaemonTruthSource(fn() => $this->mode->requestCircle($this->circleData(1, ['late-hash'])));
        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], Hilos::$rt?->hilosProtectedModeRuntime?->circleSessionTokenHashes);

        $this->mode->requestEnable($this->enableData(entryMode: StateProtectedModeRuntime::ENTRY_MODE_VERIFICATION_WINDOW));
        $this->mode->requestDisable($this->disableData(self::INITIATOR_TYPE, self::INITIATOR_INDEX));

        $this->assertSame(['notifyInitiatorReady', 'enterInactive', 'finishLift'], $this->executor->calls);
    }

    public function testARestoredUnfinishedEntryOwesNobodyAReady(): void
    {
        // The operation that waited for this ready did not survive the restart, so a roster stop
        // reaching the row marks it active and tells nobody to run. Verify still refuses by phase,
        // and the release is the way out.
        $this->restartUnder(StateProtectedModeRuntime::PHASE_ACTIVATING);

        $this->mode->requestVerify(new ProtectedModeVerifySignalData(self::INITIATOR_TYPE, self::INITIATOR_INDEX));
        $this->mode->onRosterStopped();
        $this->assertSame(['enterActive'], $this->executor->calls);

        $this->mode->requestDisable($this->disableData(self::INITIATOR_TYPE, self::INITIATOR_INDEX));

        $this->assertSame(['enterActive', 'enterDeactivating', 'enterInactive'], $this->executor->calls);
    }

    public function testAnIdleRowAdoptsNothing(): void
    {
        // The ordinary start, which the adoption must not disturb: a stray release is still dropped,
        // and the next entry is an entry.
        $this->restartUnder(StateProtectedModeRuntime::PHASE_INACTIVE);

        $this->mode->requestDisable($this->disableData(self::INITIATOR_TYPE, self::INITIATOR_INDEX));
        $this->assertSame([], $this->executor->calls);

        $this->mode->requestEnable($this->enableData());
        $this->assertSame(['enterActivating'], $this->executor->calls);
    }

    public function testARowNamingNoInitiatorIsNotAdopted(): void
    {
        // Nobody to authorize a request against, so nobody may drive it; the watchdog reports the
        // freeze as stuck and the operator ends it.
        $this->restartUnder(StateProtectedModeRuntime::PHASE_ACTIVE, initiatorAgentType: null);

        $this->mode->requestDisable($this->disableData(self::INITIATOR_TYPE, self::INITIATOR_INDEX));

        $this->assertSame([], $this->executor->calls);
    }

    /**
     * Mounts the framework-owned protected mode runtime row, as a real project boot does.
     */
    private function mount(): void
    {
        Hilos::$rt = new StandaloneProtectedModeTestRtContext();
        Hilos::$rt->mountFeatureRuntime([]);
    }

    /**
     * Records the initiator identity on the runtime row, standing in for the real executor's write.
     *
     * The release is authorized against the row rather than against the machine's own memory, so
     * with a fake executor the test has to put there what {@see DaemonProtectedModeExecutor::enterActivating()}
     * would have written. It writes through the same item actions the real executor uses, which is
     * why the daemon truth source is registered for the length of the write and dropped after: a
     * test that wrote the row any other way would stop proving the release path reads what the
     * executor actually leaves behind.
     *
     * @param string $agentType Initiator agent type to record
     * @param ?int $agentIndex Initiator agent index to record
     * @param bool $activate Whether to advance the row to active, as a completed entry does
     * @param ?string $acceptKey Initiator accept key to record, none by default
     * @param ?string $sessionTokenHash Initiator session token hash to record, none by default
     */
    private function recordInitiatorOnTheRuntimeRow(
        string $agentType,
        ?int $agentIndex,
        bool $activate = true,
        ?string $acceptKey = null,
        ?string $sessionTokenHash = null,
    ): void {
        $view = Hilos::$rt?->hilosProtectedModeRuntime;
        if ($view === null) {
            $this->fail('The protected mode runtime row is not mounted.');
        }

        $this->withDaemonTruthSource(function () use ($view, $agentType, $agentIndex, $activate, $acceptKey, $sessionTokenHash): void {
            $view->actions->enterActivating(
                new ProtectedModeQuiesceData('restore', $agentType, $agentIndex, null, $sessionTokenHash),
                $acceptKey,
            );
            if ($activate) {
                $view->actions->enterActive();
            }
        });
    }

    /**
     * Restarts the node on a freeze row: the memory goes, the row comes back from disk, and a new
     * switch is built over it and adopts it, in the order {@see DaemonManager} runs them.
     *
     * A new switch rather than the old one told something, because the freeze the old one held in
     * memory is exactly what a restarted master no longer has. The row is put back through the same
     * restore the daemon runs at boot, so the adoption reads what that rule keeps, and an inactive
     * row is not put back at all, as at boot.
     *
     * @param string $phase Phase the node went down on
     * @param string $entryMode How the freeze was entered
     * @param ?string $initiatorAgentType Initiator agent the row records, the test's initiator by default
     * @throws InvalidFormatException When the fixture row is not one the state can be built from
     */
    private function restartUnder(
        string $phase,
        string $entryMode = StateProtectedModeRuntime::ENTRY_MODE_FREEZE,
        ?string $initiatorAgentType = self::INITIATOR_TYPE,
    ): void {
        $this->mount();
        $view = Hilos::$rt?->hilosProtectedModeRuntime;
        if ($view === null) {
            $this->fail('The protected mode runtime row is not mounted.');
        }

        if ($phase !== StateProtectedModeRuntime::PHASE_INACTIVE) {
            $row = StateProtectedModeRuntime::fromRow([
                StateProtectedModeRuntime::phase => $phase,
                StateProtectedModeRuntime::entryMode => $entryMode,
                StateProtectedModeRuntime::operation => 'restore',
                StateProtectedModeRuntime::initiatorAgentType => $initiatorAgentType,
                StateProtectedModeRuntime::initiatorAgentIndex => self::INITIATOR_INDEX,
                StateProtectedModeRuntime::passHashes => [],
                StateProtectedModeRuntime::admittedSessionTokenHashes => [],
                StateProtectedModeRuntime::circleSessionTokenHashes => [],
                StateProtectedModeRuntime::circleNamedCount => 0,
            ]);
            $this->withDaemonTruthSource(static fn() => $view->actions->restoreFromDisk($row));
        }

        $this->mode = new StandaloneProtectedMode($this->executor);
        $this->mode->adoptStandingFreeze();
    }

    /**
     * Enters the freeze, opens the verification window and closes it back, as the initiator does.
     *
     * Ends with the close asked for: the fake executor writes no row, so the caller puts on it
     * whatever phase the walk has reached.
     */
    private function closeTheWindowBack(): void
    {
        $this->mode->requestEnable($this->enableData());
        $this->recordInitiatorOnTheRuntimeRow(self::INITIATOR_TYPE, self::INITIATOR_INDEX);
        $this->enterVerifyingOnTheRuntimeRow();
        $this->mode->requestRefreeze(new ProtectedModeRefreezeSignalData(self::INITIATOR_TYPE, self::INITIATOR_INDEX));
    }

    /**
     * Moves the row into the verification window, standing in for the real executor's write.
     */
    private function enterVerifyingOnTheRuntimeRow(): void
    {
        $view = Hilos::$rt?->hilosProtectedModeRuntime;
        if ($view === null) {
            $this->fail('The protected mode runtime row is not mounted.');
        }

        $this->withDaemonTruthSource(static fn() => $view->actions->enterVerifying());
    }

    private function enterDirectWindowOnTheRuntimeRow(): void
    {
        $this->mode->requestEnable($this->enableData(entryMode: StateProtectedModeRuntime::ENTRY_MODE_VERIFICATION_WINDOW));
        $view = Hilos::$rt?->hilosProtectedModeRuntime;
        if ($view === null) {
            $this->fail('The protected mode runtime row is not mounted.');
        }

        $this->withDaemonTruthSource(static fn() => $view->actions->enterVerificationWindow(
            new ProtectedModeQuiesceData('restore', self::INITIATOR_TYPE, self::INITIATOR_INDEX, null, null),
            'accept-9',
        ));
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
     * @param string $agentType Agent type asking to enter, the recorded initiator by default
     * @param ?int $agentIndex Agent index asking to enter
     * @param string $entryMode Entry variant
     * @return ProtectedModeEnableSignalData Enable request of the single-node initiator
     */
    private function enableData(
        string $agentType = self::INITIATOR_TYPE,
        ?int $agentIndex = self::INITIATOR_INDEX,
        string $entryMode = StateProtectedModeRuntime::ENTRY_MODE_FREEZE,
    ): ProtectedModeEnableSignalData {
        return new ProtectedModeEnableSignalData(
            operation: 'restore',
            initiatorAcceptKey: 'accept-9',
            initiatorSessionTokenHash: null,
            initiatorAgentType: $agentType,
            initiatorAgentIndex: $agentIndex,
            initiatorNodeId: null,
            entryMode: $entryMode,
        );
    }

    /**
     * @param int $namedCount How many people the circle named at the freeze
     * @param list<string> $sessionTokenHashes Session token hashes of the members who were online
     * @param string $agentType Agent type that photographed the circle, the recorded initiator by default
     * @param ?int $agentIndex Agent index that photographed the circle
     * @return ProtectedModeCircleSignalData Circle photograph of that agent
     */
    private function circleData(
        int $namedCount,
        array $sessionTokenHashes,
        string $agentType = self::INITIATOR_TYPE,
        ?int $agentIndex = self::INITIATOR_INDEX,
    ): ProtectedModeCircleSignalData {
        return new ProtectedModeCircleSignalData($agentType, $agentIndex, $namedCount, $sessionTokenHashes);
    }

    /**
     * @param string $agentType Agent type reporting the progress
     * @param ?int $agentIndex Agent index reporting the progress
     * @return ProtectedModeProgressSignalData Progress mark of that agent
     */
    private function progressData(string $agentType, ?int $agentIndex): ProtectedModeProgressSignalData
    {
        return new ProtectedModeProgressSignalData(
            initiatorAgentType: $agentType,
            initiatorAgentIndex: $agentIndex,
        );
    }

    /**
     * @param string $agentType Agent type asking for the release
     * @param ?int $agentIndex Agent index asking for the release
     * @return ProtectedModeDisableSignalData Release request of that agent
     */
    private function disableData(string $agentType, ?int $agentIndex): ProtectedModeDisableSignalData
    {
        return new ProtectedModeDisableSignalData(
            initiatorAgentType: $agentType,
            initiatorAgentIndex: $agentIndex,
        );
    }
}

final class StandaloneProtectedModeTestRtContext extends RtContext
{
    /**
     * Registers no project runtime state: the framework mount supplies the freeze row.
     */
    public function configure(): void
    {
    }
}

/**
 * Recording fake of the local-node port: captures the transitions and the freeze descriptor.
 */
final class FakeStandaloneExecutor implements ProtectedModeExecutor
{
    /** @var array<string> Ordered method names invoked */
    public array $calls = [];

    /** @var ?string Accept key passed to the most recent enterActivating call */
    public ?string $activatingAcceptKey = null;

    /** @var ?ProtectedModeQuiesceData Freeze descriptor passed to the most recent enterActivating call */
    public ?ProtectedModeQuiesceData $freeze = null;

    /** @var ?string Session token hash passed to the most recent enterActivating call */
    public ?string $activatingSessionTokenHash = null;

    public function enterActivating(
        ProtectedModeQuiesceData $freeze,
        ?string $initiatorAcceptKey,
    ): void {
        $this->calls[] = 'enterActivating';
        $this->activatingAcceptKey = $initiatorAcceptKey;
        $this->activatingSessionTokenHash = $freeze->initiatorSessionTokenHash;
        $this->freeze = $freeze;
    }

    public function enterVerificationWindow(
        ProtectedModeQuiesceData $freeze,
        ?string $initiatorAcceptKey,
    ): void {
        $this->calls[] = 'enterVerificationWindow';
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
final class FakeStandaloneInitiatorRelay implements ProtectedModeInitiatorRelay
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
