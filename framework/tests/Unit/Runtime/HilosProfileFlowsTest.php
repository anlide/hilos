<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Runtime;

use Hilos\Auth\StepUp\StepUpOperationKey;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Hilos;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Collection\HilosProfileFlows as StateHilosProfileFlows;
use Hilos\Runtime\State\Item\HilosProfileFlow as StateHilosProfileFlow;
use Hilos\Runtime\View\Actions\Collection\HilosProfileFlowsActions;
use Hilos\Runtime\View\Collection\HilosProfileFlows;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the profile windows half-way through (HIL-1182).
 *
 * The row is what a later step of an email or password change stands on, so what is pinned here
 * is its shape and the four ways it moves: a step written and rewritten under one id per session
 * and window, a window's flow taken away, every flow of a session taken away at once, and the rows
 * whose code has died reclaimed by the moment they copied from it.
 */
final class HilosProfileFlowsTest extends TestCase
{
    private const string AGENT_ID = 'unit-profile-flow-holder';

    private const string SESSION_HASH = 'hash-of-session-one';

    private const string OTHER_SESSION_HASH = 'hash-of-session-two';

    private const int USER_ID = 1182;

    private const string CURRENT = 'current@example.test';

    private const string NEW_EMAIL = 'new@example.test';

    private const int EXPIRES_AT = 1_900_000_000_000;

    /** Moment the earlier of two codes dies, and the moment the sweep below judges by. */
    private const int EARLIER_CODE_DIES_AT = 1000;

    private const int LATER_CODE_DIES_AT = 2000;

    private ?SignalRouter $previousSignalRouter = null;

    protected function setUp(): void
    {
        $this->previousSignalRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        RtTruthSourceRegistry::register(StateHilosProfileFlow::RT_COLLECTION, TruthSourceKeys::all(), self::AGENT_ID);
    }

    protected function tearDown(): void
    {
        RtTruthSourceRegistry::unregister(StateHilosProfileFlow::RT_COLLECTION, self::AGENT_ID);
        Hilos::$sr = $this->previousSignalRouter;

        parent::tearDown();
    }

    public function testTheIdNamesTheSessionAndTheWindow(): void
    {
        $this->assertSame(
            self::SESSION_HASH . ':' . StepUpOperationKey::CHANGE_EMAIL,
            StateHilosProfileFlow::idFor(self::SESSION_HASH, StepUpOperationKey::CHANGE_EMAIL),
        );
        $this->assertNotSame(
            StateHilosProfileFlow::idFor(self::SESSION_HASH, StepUpOperationKey::CHANGE_EMAIL),
            StateHilosProfileFlow::idFor(self::SESSION_HASH, StepUpOperationKey::CHANGE_PASSWORD),
        );
    }

    public function testARowSurvivesTheSyncBoundary(): void
    {
        $row = StateHilosProfileFlow::create(
            self::SESSION_HASH,
            StepUpOperationKey::CHANGE_EMAIL,
            self::USER_ID,
            StateHilosProfileFlow::STEP_NEW_SENT,
            self::CURRENT,
            self::NEW_EMAIL,
            self::EXPIRES_AT,
        )->toArray();

        $restored = StateHilosProfileFlow::fromRow($row);

        $this->assertSame($row, $restored->toArray());
        $this->assertSame(StateHilosProfileFlow::idFor(self::SESSION_HASH, StepUpOperationKey::CHANGE_EMAIL), $restored->getId());
    }

    public function testADiffMovesTheStepAndLeavesTheIdAlone(): void
    {
        $flow = StateHilosProfileFlow::create(
            self::SESSION_HASH,
            StepUpOperationKey::CHANGE_EMAIL,
            self::USER_ID,
            StateHilosProfileFlow::STEP_CURRENT_PROVEN,
            self::CURRENT,
            null,
            self::EXPIRES_AT,
        );

        $flow->applyDiff([
            StateHilosProfileFlow::step => StateHilosProfileFlow::STEP_NEW_SENT,
            StateHilosProfileFlow::target => self::NEW_EMAIL,
            StateHilosProfileFlow::sessionTokenHash => self::OTHER_SESSION_HASH,
        ]);

        // An absent key is a field the diff did not change; the id is not patchable at all.
        $this->assertSame(StateHilosProfileFlow::STEP_NEW_SENT, $flow->step);
        $this->assertSame(self::NEW_EMAIL, $flow->target);
        $this->assertSame(self::CURRENT, $flow->address);
        $this->assertSame(self::EXPIRES_AT, $flow->expiresAt);
        $this->assertSame(self::SESSION_HASH, $flow->sessionTokenHash);
    }

    public function testAStepIsWrittenAndThenRewrittenUnderOneRow(): void
    {
        $flows = $this->mounted();

        $flows->actions->put(
            self::SESSION_HASH,
            StepUpOperationKey::CHANGE_EMAIL,
            self::USER_ID,
            StateHilosProfileFlow::STEP_CURRENT_PROVEN,
            self::CURRENT,
            null,
            self::EXPIRES_AT,
        );
        $flows->actions->put(
            self::SESSION_HASH,
            StepUpOperationKey::CHANGE_EMAIL,
            self::USER_ID,
            StateHilosProfileFlow::STEP_NEW_SENT,
            self::CURRENT,
            self::NEW_EMAIL,
            self::EXPIRES_AT,
        );

        $this->assertCount(1, $flows);
        $flow = $flows[StateHilosProfileFlow::idFor(self::SESSION_HASH, StepUpOperationKey::CHANGE_EMAIL)];
        $this->assertSame(StateHilosProfileFlow::STEP_NEW_SENT, $flow?->step);
        $this->assertSame(self::NEW_EMAIL, $flow?->target);
    }

    public function testTwoWindowsOfOneSessionLiveApart(): void
    {
        $flows = $this->mounted();
        $this->putEmail($flows, self::SESSION_HASH);
        $this->putPassword($flows, self::SESSION_HASH);
        $this->putEmail($flows, self::OTHER_SESSION_HASH);

        $this->assertTrue($flows->actions->drop(self::SESSION_HASH, StepUpOperationKey::CHANGE_EMAIL));

        $this->assertNull($flows[StateHilosProfileFlow::idFor(self::SESSION_HASH, StepUpOperationKey::CHANGE_EMAIL)]);
        $this->assertNotNull($flows[StateHilosProfileFlow::idFor(self::SESSION_HASH, StepUpOperationKey::CHANGE_PASSWORD)]);
        $this->assertCount(1, $flows->forSessionTokenHash(self::SESSION_HASH));
        $this->assertCount(1, $flows->forSessionTokenHash(self::OTHER_SESSION_HASH));
    }

    public function testDroppingAWindowWithNoFlowIsANoOp(): void
    {
        $this->assertFalse($this->mounted()->actions->drop(self::SESSION_HASH, StepUpOperationKey::CHANGE_EMAIL));
    }

    public function testForgettingASessionTakesEveryWindowOfItAndNoOther(): void
    {
        $flows = $this->mounted();
        $this->putEmail($flows, self::SESSION_HASH);
        $this->putPassword($flows, self::SESSION_HASH);
        $this->putEmail($flows, self::OTHER_SESSION_HASH);

        $this->assertTrue($flows->actions->forget(self::SESSION_HASH));
        $this->assertFalse($flows->actions->forget(self::SESSION_HASH));

        $this->assertSame([], $flows->forSessionTokenHash(self::SESSION_HASH));
        $this->assertCount(1, $flows->forSessionTokenHash(self::OTHER_SESSION_HASH));
    }

    public function testTheRowsWhoseCodeDiedAreReclaimedByTheirOwnMoment(): void
    {
        $flows = $this->mounted();
        $this->putEmail($flows, self::SESSION_HASH, self::EARLIER_CODE_DIES_AT);
        $this->putEmail($flows, self::OTHER_SESSION_HASH, self::LATER_CODE_DIES_AT);

        // A code dying at the very moment judged is dead: the row is gone at its own moment.
        $this->assertSame(1, $flows->actions->forgetExpired(self::EARLIER_CODE_DIES_AT));

        $this->assertSame([], $flows->forSessionTokenHash(self::SESSION_HASH));
        $this->assertCount(1, $flows->forSessionTokenHash(self::OTHER_SESSION_HASH));
    }

    public function testNobodyButTheHolderWritesAFlow(): void
    {
        RtTruthSourceRegistry::unregister(StateHilosProfileFlow::RT_COLLECTION, self::AGENT_ID);

        $this->expectException(RtTruthSourceWriteNotAllowedException::class);

        $this->putEmail($this->mounted(), self::SESSION_HASH);
    }

    /**
     * @param HilosProfileFlows $flows Collection under test
     * @param string $sessionTokenHash Session the flow belongs to
     * @param int $expiresAt Epoch milliseconds the code of the proof dies at
     */
    private function putEmail(HilosProfileFlows $flows, string $sessionTokenHash, int $expiresAt = self::EXPIRES_AT): void
    {
        $flows->actions->put(
            $sessionTokenHash,
            StepUpOperationKey::CHANGE_EMAIL,
            self::USER_ID,
            StateHilosProfileFlow::STEP_CURRENT_SENT,
            self::CURRENT,
            null,
            $expiresAt,
        );
    }

    /**
     * @param HilosProfileFlows $flows Collection under test
     * @param string $sessionTokenHash Session the flow belongs to
     */
    private function putPassword(HilosProfileFlows $flows, string $sessionTokenHash): void
    {
        $flows->actions->put(
            $sessionTokenHash,
            StepUpOperationKey::CHANGE_PASSWORD,
            self::USER_ID,
            StateHilosProfileFlow::STEP_CODE_PROVEN,
            self::CURRENT,
            null,
            self::EXPIRES_AT,
        );
    }

    /**
     * @return HilosProfileFlows Collection mounted the way the runtime context mounts it
     */
    private function mounted(): HilosProfileFlows
    {
        // The state collection goes through a variable because setStateCollection() binds a
        // reference, exactly as the runtime context does when it represents a collection.
        $states = StateHilosProfileFlows::init();
        $flows = HilosProfileFlows::init();
        $flows->setStateCollection($states);
        $flows->setCollectionName(StateHilosProfileFlow::RT_COLLECTION);
        $flows->setActionsClass(HilosProfileFlowsActions::class);

        return $flows;
    }
}
