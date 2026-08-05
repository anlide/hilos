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
 * delegate answering per phase and the four transitions leaving the row a reader can act on.
 */
final class ProtectedModeRuntimeViewTest extends TestCase
{
    private const string INITIATOR_KEY = 'accept-9';

    protected function tearDown(): void
    {
        RtTruthSourceRegistry::unregisterDaemon(StateProtectedModeRuntime::RT_ITEM);

        parent::tearDown();
    }

    public function testAnInactiveFreezeLocksNobodyOut(): void
    {
        $state = StateProtectedModeRuntime::create();

        $this->assertFalse(new ProtectedModeRuntime($state)->locksOut('any-key'));
    }

    public function testTheInitiatorStaysLiveWhileEveryoneElseIsLockedOut(): void
    {
        $view = $this->frozenView();

        $this->assertFalse($view->locksOut(self::INITIATOR_KEY));
        $this->assertTrue($view->locksOut('accept-1'));
        // A connection with no key of its own is not the initiator either.
        $this->assertTrue($view->locksOut(null));
    }

    public function testEnterActivatingRecordsTheFreezeAndItsInitiator(): void
    {
        RtTruthSourceRegistry::registerDaemon(StateProtectedModeRuntime::RT_ITEM);
        $state = StateProtectedModeRuntime::create();
        $view = $this->viewWithActions($state);

        $view->actions->enterActivating($this->freeze(), self::INITIATOR_KEY);

        $this->assertSame(StateProtectedModeRuntime::PHASE_ACTIVATING, $view->phase);
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
        $view->actions->enterActivating($this->freeze(), self::INITIATOR_KEY);

        $view->actions->enterActive();

        $this->assertSame(StateProtectedModeRuntime::PHASE_ACTIVE, $view->phase);
        $this->assertNotNull($view->activatedAt);
    }

    public function testEnterDeactivatingKeepsTheLockdownUp(): void
    {
        RtTruthSourceRegistry::registerDaemon(StateProtectedModeRuntime::RT_ITEM);
        $state = StateProtectedModeRuntime::create();
        $view = $this->viewWithActions($state);
        $view->actions->enterActivating($this->freeze(), self::INITIATOR_KEY);

        $view->actions->enterDeactivating();

        $this->assertSame(StateProtectedModeRuntime::PHASE_DEACTIVATING, $view->phase);
        // Lifting is not lifted: everyone but the initiator stays out until the row is inactive.
        $this->assertTrue($view->locksOut('accept-1'));
    }

    public function testEnterInactiveForgetsTheInitiatorWithTheFreeze(): void
    {
        RtTruthSourceRegistry::registerDaemon(StateProtectedModeRuntime::RT_ITEM);
        $state = StateProtectedModeRuntime::create();
        $view = $this->viewWithActions($state);
        $view->actions->enterActivating($this->freeze(), self::INITIATOR_KEY);
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
        $this->assertFalse($view->locksOut('accept-1'));
    }

    public function testAWriterThatIsNotTheTruthSourceIsRefused(): void
    {
        $state = StateProtectedModeRuntime::create();
        $view = $this->viewWithActions($state);

        $this->expectException(RtTruthSourceWriteNotAllowedException::class);
        $view->actions->enterActivating($this->freeze(), self::INITIATOR_KEY);
    }

    /**
     * @return ProtectedModeRuntime View over a node frozen for the initiator's restore
     */
    private function frozenView(): ProtectedModeRuntime
    {
        $state = StateProtectedModeRuntime::fromRow([
            StateProtectedModeRuntime::phase => StateProtectedModeRuntime::PHASE_ACTIVE,
            StateProtectedModeRuntime::operation => 'restore',
            StateProtectedModeRuntime::initiatorAcceptKey => self::INITIATOR_KEY,
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
    private function viewWithActions(StateProtectedModeRuntime &$state): ProtectedModeRuntime
    {
        $view = new ProtectedModeRuntime($state);
        $view->setItemActionsClass(ProtectedModeRuntimeActions::class);

        return $view;
    }
}
