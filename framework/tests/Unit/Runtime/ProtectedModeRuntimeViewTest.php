<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Runtime;

use Hilos\ProtectedMode\DTO\ProtectedModeQuiesceData;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Actions\Item\ProtectedModeRuntimeActions;
use Hilos\Runtime\View\Item\ProtectedModeRuntime;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the protected-mode runtime singleton's view layer.
 *
 * Both lockdown checks in the framework now ask this view whether a connection is frozen
 * out, and the freeze itself is written through its actions, so what is pinned here is the
 * delegate answering per phase and the five transitions leaving the row a reader can act on.
 * The verification window adds two writers of its own - a minted pass and an admitted
 * connection - and what is pinned about them is mostly where they stop being true.
 */
final class ProtectedModeRuntimeViewTest extends TestCase
{
    private const string INITIATOR_KEY = 'accept-9';

    private const string INITIATOR_SESSION_HASH = 'session-hash-9';

    protected function tearDown(): void
    {
        RtTruthSourceRegistry::unregisterDaemon(StateProtectedModeRuntime::RT_ITEM);

        parent::tearDown();
    }

    public function testAnInactiveFreezeLocksNobodyOut(): void
    {
        $state = StateProtectedModeRuntime::create();

        $this->assertFalse(new ProtectedModeRuntime($state)->locksOut('any-key', null));
    }

    public function testAFrozenNodeLocksTheInitiatorOutWithEveryoneElse(): void
    {
        $view = $this->frozenView();

        $this->assertTrue($view->locksOut(self::INITIATOR_KEY, null));
        $this->assertTrue($view->locksOut('accept-1', null));
        // A connection with no key of its own is not the initiator either.
        $this->assertTrue($view->locksOut(null, null));
    }

    public function testEveryTabOfTheInitiatorSessionWaitsOutTheFreezeTogether(): void
    {
        $view = $this->frozenView(self::INITIATOR_SESSION_HASH);

        // The tab that asked, by its accept key, and the reload that replaced it, by its session:
        // both halves name the same operator, and while the node is frozen both halves wait. That
        // is the point of the leaf - two tabs of one person must not disagree about the screen.
        $this->assertTrue($view->locksOut(self::INITIATOR_KEY, self::INITIATOR_SESSION_HASH));
        $this->assertTrue($view->locksOut('accept-reloaded', self::INITIATOR_SESSION_HASH));
        $this->assertTrue($view->locksOut('accept-stranger', 'session-hash-stranger'));
    }

    public function testEveryTabOfTheInitiatorSessionIsLetInOnceTheWindowOpens(): void
    {
        // Same two halves one phase later: the system is live again, so both carry the operator
        // back in at once and neither tab needs an F5 the other did not need.
        $view = $this->verifyingView(self::INITIATOR_SESSION_HASH);

        $this->assertFalse($view->locksOut(self::INITIATOR_KEY, self::INITIATOR_SESSION_HASH));
        $this->assertFalse($view->locksOut('accept-reloaded', self::INITIATOR_SESSION_HASH));
        $this->assertTrue($view->locksOut('accept-stranger', 'session-hash-stranger'));
    }

    public function testAFreezeWithNoInitiatorSessionLocksOutEverySessionlessConnection(): void
    {
        // A CLI or scheduled operation records no session, and a connection carrying no cookie
        // hashes to null too: letting those two nulls meet would open the node to everybody.
        $view = $this->frozenView();

        $this->assertTrue($view->locksOut('accept-1', null));
        $this->assertTrue($view->locksOut(null, null));
        $this->assertTrue($view->locksOut('accept-1', self::INITIATOR_SESSION_HASH));
    }

    public function testEnterInactiveForgetsTheInitiatorSessionToo(): void
    {
        RtTruthSourceRegistry::registerDaemon(StateProtectedModeRuntime::RT_ITEM);
        $state = StateProtectedModeRuntime::create();
        $view = $this->viewWithActions($state);
        $view->actions->enterActivating($this->freeze(), self::INITIATOR_KEY, self::INITIATOR_SESSION_HASH);

        $view->actions->enterInactive();

        // A session hash left standing would outlive the operation that earned it by as long as
        // the browser keeps its cookie - a far longer privilege than a stale accept key.
        $this->assertNull($view->initiatorSessionTokenHash);
        $this->assertFalse($view->locksOut('accept-1', self::INITIATOR_SESSION_HASH));
    }

    public function testEnterActivatingRecordsTheFreezeAndItsInitiator(): void
    {
        RtTruthSourceRegistry::registerDaemon(StateProtectedModeRuntime::RT_ITEM);
        $state = StateProtectedModeRuntime::create();
        $view = $this->viewWithActions($state);

        $view->actions->enterActivating($this->freeze(), self::INITIATOR_KEY, self::INITIATOR_SESSION_HASH);

        $this->assertSame(StateProtectedModeRuntime::PHASE_ACTIVATING, $view->phase);
        $this->assertSame(self::INITIATOR_SESSION_HASH, $view->initiatorSessionTokenHash);
        $this->assertSame('restore', $view->operation);
        $this->assertSame(self::INITIATOR_KEY, $view->initiatorAcceptKey);
        $this->assertSame('backup', $view->initiatorAgentType);
        $this->assertSame(2, $view->initiatorAgentIndex);
        $this->assertSame('node-a', $view->initiatorNodeId);
        $this->assertNotNull($view->startedAt);
        $this->assertNull($view->activatedAt);
    }

    public function testEnterActiveStampsTheMomentEveryNodeQuiesced(): void
    {
        RtTruthSourceRegistry::registerDaemon(StateProtectedModeRuntime::RT_ITEM);
        $state = StateProtectedModeRuntime::create();
        $view = $this->viewWithActions($state);
        $view->actions->enterActivating($this->freeze(), self::INITIATOR_KEY, null);

        $view->actions->enterActive();

        $this->assertSame(StateProtectedModeRuntime::PHASE_ACTIVE, $view->phase);
        $this->assertNotNull($view->activatedAt);
    }

    public function testEnterDeactivatingKeepsTheLockdownUp(): void
    {
        RtTruthSourceRegistry::registerDaemon(StateProtectedModeRuntime::RT_ITEM);
        $state = StateProtectedModeRuntime::create();
        $view = $this->viewWithActions($state);
        $view->actions->enterActivating($this->freeze(), self::INITIATOR_KEY, null);

        $view->actions->enterDeactivating();

        $this->assertSame(StateProtectedModeRuntime::PHASE_DEACTIVATING, $view->phase);
        // Lifting is not lifted: the way out of the freeze is the lift, not the window, so this
        // phase holds every connection - the initiator's included - until the row is inactive.
        $this->assertTrue($view->locksOut('accept-1', null));
        $this->assertTrue($view->locksOut(self::INITIATOR_KEY, null));
    }

    public function testEnterInactiveForgetsTheInitiatorWithTheFreeze(): void
    {
        RtTruthSourceRegistry::registerDaemon(StateProtectedModeRuntime::RT_ITEM);
        $state = StateProtectedModeRuntime::create();
        $view = $this->viewWithActions($state);
        $view->actions->enterActivating($this->freeze(), self::INITIATOR_KEY, null);
        $view->actions->enterActive();

        $view->actions->enterInactive();

        $this->assertSame(StateProtectedModeRuntime::PHASE_INACTIVE, $view->phase);
        $this->assertNull($view->operation);
        $this->assertNull($view->initiatorAcceptKey);
        $this->assertNull($view->initiatorAgentType);
        $this->assertNull($view->initiatorAgentIndex);
        $this->assertNull($view->initiatorNodeId);
        $this->assertNull($view->startedAt);
        $this->assertNull($view->activatedAt);
        // The accept key is gone with the freeze, so the ex-initiator holds no privilege.
        $this->assertFalse($view->locksOut('accept-1', null));
    }

    public function testEnterVerifyingKeepsTheInitiatorDriving(): void
    {
        RtTruthSourceRegistry::registerDaemon(StateProtectedModeRuntime::RT_ITEM);
        $state = StateProtectedModeRuntime::create();
        $view = $this->viewWithActions($state);
        $view->actions->enterActivating($this->freeze(), self::INITIATOR_KEY, null);
        $view->actions->enterActive();

        $view->actions->enterVerifying();

        $this->assertSame(StateProtectedModeRuntime::PHASE_VERIFYING, $view->phase);
        // The operation is over, but the identity that ran it is what may end this phase.
        $this->assertSame('restore', $view->operation);
        $this->assertSame(self::INITIATOR_KEY, $view->initiatorAcceptKey);
        $this->assertSame('backup', $view->initiatorAgentType);
        $this->assertSame(2, $view->initiatorAgentIndex);
        $this->assertSame('node-a', $view->initiatorNodeId);
        // Nobody has a pass yet, so everyone else is still on the stub.
        $this->assertTrue($view->locksOut('accept-1', null));
    }

    public function testAPassAdmitsTheSessionThatPresentedIt(): void
    {
        RtTruthSourceRegistry::registerDaemon(StateProtectedModeRuntime::RT_ITEM);
        $state = StateProtectedModeRuntime::create();
        $view = $this->viewWithActions($state);
        $view->actions->enterActivating($this->freeze(), self::INITIATOR_KEY, null);
        $view->actions->enterActive();
        $view->actions->enterVerifying();

        $view->actions->issuePass('hash-a');
        $view->actions->issuePass('hash-b');
        $view->actions->admitSession('session-hash-1');

        $this->assertSame(['hash-a', 'hash-b'], $view->passHashes);
        $this->assertSame(['session-hash-1'], $view->admittedSessionTokenHashes);
        $this->assertTrue($view->admits('session-hash-1'));
        $this->assertFalse($view->locksOut('accept-1', 'session-hash-1'));
        $this->assertTrue($view->locksOut('accept-2', 'session-hash-2'));
    }

    public function testTheSameSessionPresentingThePassAgainAddsNoSecondEntry(): void
    {
        RtTruthSourceRegistry::registerDaemon(StateProtectedModeRuntime::RT_ITEM);
        $state = StateProtectedModeRuntime::create();
        $view = $this->viewWithActions($state);
        $view->actions->enterActivating($this->freeze(), self::INITIATOR_KEY, null);
        $view->actions->enterActive();
        $view->actions->enterVerifying();

        // A verifier reconnecting, or opening a second tab that still remembers the code, arrives
        // with a brand new accept key and the same cookie. Without the guard the list would grow
        // by one on every one of those.
        $view->actions->admitSession('session-hash-1');
        $view->actions->admitSession('session-hash-1');

        $this->assertSame(['session-hash-1'], $view->admittedSessionTokenHashes);
    }

    public function testASecondBrowserWithTheSamePassIsAdmittedAsItsOwnEntry(): void
    {
        RtTruthSourceRegistry::registerDaemon(StateProtectedModeRuntime::RT_ITEM);
        $state = StateProtectedModeRuntime::create();
        $view = $this->viewWithActions($state);
        $view->actions->enterActivating($this->freeze(), self::INITIATOR_KEY, null);
        $view->actions->enterActive();
        $view->actions->enterVerifying();

        // The code is deliberately reusable, so a second verifier reading it over the phone is a
        // second session and a second entry - the circle is a list, not a single holder.
        $view->actions->admitSession('session-hash-1');
        $view->actions->admitSession('session-hash-2');

        $this->assertSame(['session-hash-1', 'session-hash-2'], $view->admittedSessionTokenHashes);
        $this->assertTrue($view->admits('session-hash-1'));
        $this->assertTrue($view->admits('session-hash-2'));
    }

    public function testClosingBackToActiveVoidsEveryPass(): void
    {
        RtTruthSourceRegistry::registerDaemon(StateProtectedModeRuntime::RT_ITEM);
        $state = StateProtectedModeRuntime::create();
        $view = $this->viewWithActions($state);
        $view->actions->enterActivating($this->freeze(), self::INITIATOR_KEY, null);
        $view->actions->enterActive();
        $view->actions->enterVerifying();
        $view->actions->issuePass('hash-a');
        $view->actions->admitSession('session-hash-1');

        $view->actions->enterActive();

        $this->assertSame(StateProtectedModeRuntime::PHASE_ACTIVE, $view->phase);
        $this->assertSame([], $view->passHashes);
        $this->assertSame([], $view->admittedSessionTokenHashes);
        // The verifier is back on the stub with everyone else, and its session buys nothing.
        $this->assertTrue($view->locksOut('accept-1', 'session-hash-1'));
    }

    public function testANewFreezeStartsWithNoPassesWhateverTheRowHeld(): void
    {
        // The row a node re-enters a freeze on is not always an empty one: a demoted leader still
        // in the window is quiesced again by whoever took leadership, and its abandoned passes
        // would otherwise ride into the next operation and admit their holders to it.
        RtTruthSourceRegistry::registerDaemon(StateProtectedModeRuntime::RT_ITEM);
        $state = StateProtectedModeRuntime::create();
        $view = $this->viewWithActions($state);
        $view->actions->enterActivating($this->freeze(), self::INITIATOR_KEY, null);
        $view->actions->enterActive();
        $view->actions->enterVerifying();
        $view->actions->issuePass('hash-a');
        $view->actions->admitSession('session-hash-1');

        $view->actions->enterActivating($this->freeze(), self::INITIATOR_KEY, null);

        $this->assertSame(StateProtectedModeRuntime::PHASE_ACTIVATING, $view->phase);
        $this->assertSame([], $view->passHashes);
        $this->assertSame([], $view->admittedSessionTokenHashes);
    }

    public function testLiftingTheModeVoidsEveryPass(): void
    {
        RtTruthSourceRegistry::registerDaemon(StateProtectedModeRuntime::RT_ITEM);
        $state = StateProtectedModeRuntime::create();
        $view = $this->viewWithActions($state);
        $view->actions->enterActivating($this->freeze(), self::INITIATOR_KEY, null);
        $view->actions->enterActive();
        $view->actions->enterVerifying();
        $view->actions->issuePass('hash-a');
        $view->actions->admitSession('session-hash-1');
        $view->actions->enterDeactivating();

        $view->actions->enterInactive();

        $this->assertSame([], $view->passHashes);
        $this->assertSame([], $view->admittedSessionTokenHashes);
    }

    public function testAWriterThatIsNotTheTruthSourceIsRefused(): void
    {
        $state = StateProtectedModeRuntime::create();
        $view = $this->viewWithActions($state);

        $this->expectException(RtTruthSourceWriteNotAllowedException::class);
        $view->actions->enterActivating($this->freeze(), self::INITIATOR_KEY, null);
    }

    /**
     * @param ?string $initiatorSessionTokenHash Session hash the freeze recognizes, or null when it names none
     * @return ProtectedModeRuntime View over a node frozen for the initiator's restore
     */
    private function frozenView(?string $initiatorSessionTokenHash = null): ProtectedModeRuntime
    {
        return $this->viewInPhase(StateProtectedModeRuntime::PHASE_ACTIVE, $initiatorSessionTokenHash);
    }

    /**
     * @param ?string $initiatorSessionTokenHash Session hash the freeze recognizes, or null when it names none
     * @return ProtectedModeRuntime View over a node whose verification window is open
     */
    private function verifyingView(?string $initiatorSessionTokenHash = null): ProtectedModeRuntime
    {
        return $this->viewInPhase(StateProtectedModeRuntime::PHASE_VERIFYING, $initiatorSessionTokenHash);
    }

    /**
     * @param string $phase Freeze phase to mount the row in
     * @param ?string $initiatorSessionTokenHash Session hash the freeze recognizes, or null when it names none
     * @return ProtectedModeRuntime View over a node in that phase, frozen for the initiator's restore
     */
    private function viewInPhase(string $phase, ?string $initiatorSessionTokenHash): ProtectedModeRuntime
    {
        $state = StateProtectedModeRuntime::fromRow([
            StateProtectedModeRuntime::phase => $phase,
            StateProtectedModeRuntime::operation => 'restore',
            StateProtectedModeRuntime::initiatorAcceptKey => self::INITIATOR_KEY,
            StateProtectedModeRuntime::initiatorSessionTokenHash => $initiatorSessionTokenHash,
            StateProtectedModeRuntime::passHashes => [],
            StateProtectedModeRuntime::admittedSessionTokenHashes => [],
        ]);

        return new ProtectedModeRuntime($state);
    }

    /**
     * @return ProtectedModeQuiesceData Freeze descriptor the executor hands to the actions
     */
    private function freeze(): ProtectedModeQuiesceData
    {
        return new ProtectedModeQuiesceData('restore', 'backup', 2, 'node-a');
    }

    /**
     * Builds the view with its item actions wired, the way the runtime context does.
     *
     * @param StateProtectedModeRuntime $state Backing row the view wraps
     * @return ProtectedModeRuntime View whose actions are usable
     */
    private function viewWithActions(StateProtectedModeRuntime $state): ProtectedModeRuntime
    {
        $view = new ProtectedModeRuntime($state);
        $view->setItemActionsClass(ProtectedModeRuntimeActions::class);

        return $view;
    }
}
