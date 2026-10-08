<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Auth\Throttle\ThrottleGate;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for how the worker's half of the guard measures a silent throttle agent (HIL-1280).
 *
 * Time is synthetic throughout: the gate is handed the moment a question was asked and the
 * moment it is judged, so what is pinned is the measure itself - where a silence starts, what
 * ends it, and where the grace turns a missed verdict from "run it" into a refusal.
 */
final class AuthThrottleOutageGraceTest extends TestCase
{
    private const int GRACE_SECONDS = 5;

    private const float ASKED_AT = 1000.0;

    public function testASilenceShorterThanTheGraceLetsTheActionRun(): void
    {
        $gate = new ThrottleGate();

        $this->assertNull($gate->silenceRefusal(self::ASKED_AT, self::ASKED_AT + 4.9, self::GRACE_SECONDS));
    }

    public function testASilenceAsLongAsTheGraceRefusesForTheGrace(): void
    {
        $gate = new ThrottleGate();

        $this->assertSame(
            self::GRACE_SECONDS,
            $gate->silenceRefusal(self::ASKED_AT, self::ASKED_AT + self::GRACE_SECONDS, self::GRACE_SECONDS),
        );
    }

    public function testTheSilenceStartsWhenTheFirstUnansweredQuestionWasAsked(): void
    {
        $gate = new ThrottleGate();

        // Judged a tick and a half after it asked: the silence is already that long, not zero.
        $this->assertNull($gate->silenceRefusal(self::ASKED_AT, self::ASKED_AT + 1.5, self::GRACE_SECONDS));

        // A later question that also went unanswered does not restart the measure: counted from its own
        // moment it would be a silence of 1.5 s, counted from the first question it is past the grace.
        $this->assertSame(
            self::GRACE_SECONDS,
            $gate->silenceRefusal(self::ASKED_AT + 4.0, self::ASKED_AT + 5.5, self::GRACE_SECONDS),
        );
    }

    public function testAnAnswerEndsTheSilenceAndTheNextMissStartsItAnew(): void
    {
        $gate = new ThrottleGate();
        $this->assertSame(
            self::GRACE_SECONDS,
            $gate->silenceRefusal(self::ASKED_AT, self::ASKED_AT + 6.0, self::GRACE_SECONDS),
        );

        $gate->noteAnswered(self::ASKED_AT + 7.0);

        $this->assertNull($gate->silenceRefusal(self::ASKED_AT + 10.0, self::ASKED_AT + 11.0, self::GRACE_SECONDS));
    }

    public function testAnAnswerWithNoSilenceGoingOnChangesNothing(): void
    {
        $gate = new ThrottleGate();

        $gate->noteAnswered(self::ASKED_AT);

        $this->assertNull($gate->silenceRefusal(self::ASKED_AT + 1.0, self::ASKED_AT + 2.0, self::GRACE_SECONDS));
    }

    public function testAZeroGraceRefusesTheFirstMissForOneSecond(): void
    {
        $gate = new ThrottleGate();

        $this->assertSame(1, $gate->silenceRefusal(self::ASKED_AT, self::ASKED_AT + 0.001, 0));
    }
}
