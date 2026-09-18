<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\RestoreReleaseGate;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the freeze-lift wait that lives with the restore, not with the master (HIL-969).
 *
 * What is pinned here is the debt, not a guess: a node owed nothing does not park; a node owed
 * something waits for the answer and not for the clock; the clock still wins in the end and says
 * so; a debt nobody answered for cannot follow the node into its next freeze; and a debt nobody
 * will answer is not waited for at all.
 */
final class RestoreReleaseGateTest extends TestCase
{
    /** @var float A moment far enough from zero that a deadline computed from it is still in range */
    private const float NOW = 1000.0;

    /** @var float Seconds past the hold after which the wait has certainly run out */
    private const float PAST_THE_WAIT = 60.0;

    public function testANodeOwedNothingDoesNotPark(): void
    {
        $gate = new RestoreReleaseGate();

        $this->assertFalse($gate->holdRelease(self::NOW, true));
    }

    public function testTheReleaseWaitsForTheLoginsARestoreLeftBehind(): void
    {
        $gate = new RestoreReleaseGate();
        $gate->noteSessionsDeferred(3);

        $this->assertTrue($this->quietly(fn() => $gate->holdRelease(self::NOW, true)));
        $this->assertFalse($gate->releaseDue(self::NOW));
        $this->assertFalse($gate->releaseDue(self::NOW + 9.0));
    }

    public function testTheAnswerReleasesTheHeldRequest(): void
    {
        $gate = new RestoreReleaseGate();
        $gate->noteSessionsDeferred(3);
        $this->quietly(fn() => $gate->holdRelease(self::NOW, true));

        $gate->noteSessionsCarriedOver(2, 1, 0);

        $this->assertTrue(
            $this->quietly(fn() => $gate->holdRelease(self::NOW, true)),
            'The receipt zeros the debt but leaves the parking; the caller asks releaseDue()',
        );
        $this->assertTrue($this->quietly(fn() => $gate->releaseDue(self::NOW)));
        $this->assertFalse($gate->releaseDue(self::NOW + self::PAST_THE_WAIT));
    }

    public function testAFailedPassStillReleasesTheHeldRequest(): void
    {
        $gate = new RestoreReleaseGate();
        $gate->noteSessionsDeferred(3);
        $this->quietly(fn() => $gate->holdRelease(self::NOW, true));

        $gate->noteSessionsCarriedOver(0, 3, 0);

        $this->assertTrue($this->quietly(fn() => $gate->releaseDue(self::NOW)));
    }

    public function testTheHeldRequestGoesOutOnItsOwnWhenNoAnswerComes(): void
    {
        $gate = new RestoreReleaseGate();
        $gate->noteSessionsDeferred(3);
        $this->quietly(fn() => $gate->holdRelease(self::NOW, true));

        $this->assertFalse($gate->releaseDue(self::NOW));
        $this->assertTrue($this->quietly(fn() => $gate->releaseDue(self::NOW + self::PAST_THE_WAIT)));
        $this->assertFalse($gate->releaseDue(self::NOW + self::PAST_THE_WAIT));
    }

    public function testADebtNobodyAnsweredDoesNotFollowTheNodeIntoTheNextFreeze(): void
    {
        $gate = new RestoreReleaseGate();
        $gate->noteSessionsDeferred(3);
        $this->quietly(fn() => $gate->holdRelease(self::NOW, true));
        $gate->forgetSessionsOwed();

        $this->assertFalse($gate->releaseDue(self::NOW + self::PAST_THE_WAIT));
        $this->assertFalse($gate->holdRelease(self::NOW, true));
    }

    public function testAnAnswerNobodyWasWaitingForIsHarmless(): void
    {
        $gate = new RestoreReleaseGate();
        $gate->noteSessionsDeferred(3);
        $gate->noteSessionsCarriedOver(3, 0, 0);

        $this->assertFalse($gate->holdRelease(self::NOW, true));
        $this->assertFalse($gate->releaseDue(self::NOW + self::PAST_THE_WAIT));
    }

    public function testAQueueWithNoOwnerDoesNotPark(): void
    {
        $gate = new RestoreReleaseGate();
        $gate->noteSessionsDeferred(3);

        $this->assertFalse($this->quietly(fn() => $gate->holdRelease(self::NOW, false)));
        $this->assertFalse($gate->releaseDue(self::NOW + self::PAST_THE_WAIT));
    }

    public function testASecondHoldDoesNotResetTheDeadline(): void
    {
        $gate = new RestoreReleaseGate();
        $gate->noteSessionsDeferred(3);
        $this->quietly(fn() => $gate->holdRelease(self::NOW, true));

        $this->assertTrue($this->quietly(fn() => $gate->holdRelease(self::NOW + self::PAST_THE_WAIT, true)));
        $this->assertTrue($this->quietly(fn() => $gate->releaseDue(self::NOW + self::PAST_THE_WAIT)));
    }

    /**
     * Runs a call that may write an agent line to stdout, and returns its result.
     *
     * @template T
     * @param callable(): T $fn Call that may echo a log line
     * @return T The call's return value
     */
    private function quietly(callable $fn): mixed
    {
        ob_start();
        try {
            return $fn();
        } finally {
            ob_end_clean();
        }
    }
}
