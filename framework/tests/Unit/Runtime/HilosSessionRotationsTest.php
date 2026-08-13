<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Runtime;

use Hilos\Constants\TimeConstants;
use Hilos\Core\Router\SignalRouter;
use Hilos\Hilos;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Collection\HilosSessionRotations as StateHilosSessionRotations;
use Hilos\Runtime\State\Item\HilosSessionRotation as StateHilosSessionRotation;
use Hilos\Runtime\View\Actions\Collection\HilosSessionRotationsActions;
use Hilos\Runtime\View\Collection\HilosSessionRotations;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pending token rotations (HIL-582).
 *
 * The collection is what stands between a login and the browser's new cookie, so what is
 * pinned here is the lifetime of a ticket and the two ways a row leaves. A ticket presented
 * inside its moment yields the rotated token; the same ticket presented after it - or a
 * ticket nobody minted - yields nothing at all, which is what makes the master fall back to
 * the ordinary cookie rule instead of handing out a session. And the sweep exists so that a
 * rotation nobody spent stops occupying the process rather than waiting for a restart.
 */
final class HilosSessionRotationsTest extends TestCase
{
    private const string TICKET = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

    private const string NEW_TOKEN = '0f1e2d3c4b5a69788796a5b4c3d2e1f0';

    private const string AGENT_ID = 'unit-session-host';

    private ?SignalRouter $previousSignalRouter = null;

    protected function setUp(): void
    {
        $this->previousSignalRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        RtTruthSourceRegistry::register(StateHilosSessionRotation::RT_COLLECTION, true, self::AGENT_ID);
    }

    protected function tearDown(): void
    {
        RtTruthSourceRegistry::unregister(StateHilosSessionRotation::RT_COLLECTION, self::AGENT_ID);
        Hilos::$sr = $this->previousSignalRouter;

        parent::tearDown();
    }

    public function testALiveTicketYieldsTheRotationItNames(): void
    {
        $rotations = $this->mounted();
        $rotations->actions->register(self::TICKET, self::NEW_TOKEN, ['ak-second'], $this->inMs(30));

        $claimed = $rotations->claimable(self::TICKET);

        $this->assertNotNull($claimed);
        $this->assertSame(self::TICKET, $claimed->ticket);
        $this->assertSame(self::NEW_TOKEN, $claimed->sessionToken);
        $this->assertSame(['ak-second'], $claimed->acceptKeysToDrop);
    }

    public function testAnExpiredTicketYieldsNothingAlthoughItsRowIsStillThere(): void
    {
        $rotations = $this->mounted();
        $rotations->actions->register(self::TICKET, self::NEW_TOKEN, [], $this->inMs(-1));

        // The row survives until the sweep, so the refusal has to come from the read.
        $this->assertNotNull($rotations->getStateCollection()->get(self::TICKET));
        $this->assertNull($rotations->claimable(self::TICKET));
    }

    public function testAnUnknownTicketYieldsNothing(): void
    {
        $this->assertNull($this->mounted()->claimable(self::TICKET));
    }

    public function testForgetBurnsTheTicketSoASecondHandshakeGetsNothing(): void
    {
        $rotations = $this->mounted();
        $rotations->actions->register(self::TICKET, self::NEW_TOKEN, [], $this->inMs(30));

        $rotations->actions->forget(self::TICKET);

        $this->assertNull($rotations->claimable(self::TICKET));
    }

    public function testForgettingATicketThatIsNotThereIsNotAnError(): void
    {
        $rotations = $this->mounted();

        $rotations->actions->forget(self::TICKET);

        $this->expectNotToPerformAssertions();
    }

    public function testTheSweepDropsExpiredRowsAndKeepsLiveOnes(): void
    {
        $rotations = $this->mounted();
        $rotations->actions->register(self::TICKET, self::NEW_TOKEN, [], $this->inMs(-1));
        $rotations->actions->register(str_repeat('b', 32), self::NEW_TOKEN, [], $this->inMs(30));

        $dropped = $rotations->actions->forgetExpired();

        $this->assertSame(1, $dropped);
        $this->assertNull($rotations->getStateCollection()->get(self::TICKET));
        $this->assertNotNull($rotations->claimable(str_repeat('b', 32)));
    }

    public function testAnAgentThatIsNotTheTruthSourceCannotAnnounceARotation(): void
    {
        $rotations = $this->mounted();
        RtTruthSourceRegistry::unregister(StateHilosSessionRotation::RT_COLLECTION, self::AGENT_ID);

        $this->expectException(RtTruthSourceWriteNotAllowedException::class);
        $rotations->actions->register(self::TICKET, self::NEW_TOKEN, [], $this->inMs(30));
    }

    /**
     * Builds the collection the way the runtime context mounts it.
     *
     * @return HilosSessionRotations Rotations collection bound to a fresh state collection
     */
    private function mounted(): HilosSessionRotations
    {
        // The state collection goes through a variable because setStateCollection() binds a
        // reference, exactly as the runtime context does when it represents a collection.
        $states = StateHilosSessionRotations::init();
        $rotations = HilosSessionRotations::init();
        $rotations->setStateCollection($states);
        $rotations->setCollectionName(StateHilosSessionRotation::RT_COLLECTION);
        $rotations->setActionsClass(HilosSessionRotationsActions::class);

        return $rotations;
    }

    /**
     * @param float $seconds Offset from now; negative values land in the past
     * @return float Unix milliseconds that many seconds from now
     */
    private function inMs(float $seconds): float
    {
        return (microtime(true) + $seconds) * TimeConstants::MS_PER_SECOND;
    }
}
