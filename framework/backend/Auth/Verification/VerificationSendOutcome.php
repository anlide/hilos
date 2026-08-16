<?php

declare(strict_types=1);

namespace Hilos\Auth\Verification;

use Hilos\Constants\TimeConstants;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * VerificationSendOutcome - what {@see VerificationService::issue()} answers a send site (HIL-421).
 *
 * Issuing stopped being silent: a caller has to know whether a code really went
 * out, because the registration hold is extended only for a letter that was sent,
 * and the surface needs the seconds before it may offer a resend. Three outcomes,
 * and the pair of flags tells them apart: sent, held by the cooldown (nothing sent,
 * the countdown says when), cap reached (nothing sent, and no countdown will fix
 * it - the caller refuses out loud instead).
 *
 * A refusal carries no reason beyond these flags on purpose: whether an address
 * exists is never part of this answer, and the caller decides how loudly to say no
 * (magic link says nothing at all).
 */
final class VerificationSendOutcome
{
    /**
     * @param bool $sent Whether a code was really issued and handed to the deliverer
     * @param bool $capReached Whether the window cap refused the send
     * @param int $resendInSeconds Seconds until a send is allowed again, 0 when nothing is pending
     */
    public function __construct(
        public readonly bool $sent,
        public readonly bool $capReached,
        public readonly int $resendInSeconds,
    ) {
    }

    /**
     * @param int $resendInSeconds Seconds of cooldown the fresh send starts
     * @return self Outcome of a code that went out
     */
    public static function sent(int $resendInSeconds): self
    {
        return new self(true, false, $resendInSeconds);
    }

    /**
     * Builds the outcome of a send the cooldown swallowed.
     *
     * Nothing was sent and the caller still succeeds: a repeat pressed too soon is
     * not an error, it is a countdown the surface draws.
     *
     * @param int $resendInSeconds Seconds left of the cooldown
     * @return self Outcome of a send held back
     */
    public static function heldByCooldown(int $resendInSeconds): self
    {
        return new self(false, false, $resendInSeconds);
    }

    /**
     * Builds the outcome of a send the window cap refused.
     *
     * Carries no seconds: waiting out a cooldown would not help, so a countdown
     * here would promise a button that is not coming back that soon.
     *
     * @return self Outcome of a send over the cap
     */
    public static function capReached(): self
    {
        return new self(false, true, 0);
    }

    /**
     * The server moment the next send unblocks, in epoch milliseconds (HIL-486).
     *
     * The seconds are what the cooldown is measured in, and a moment is what the
     * browser can be told: a countdown handed over as a duration is wrong the
     * instant the tab is reloaded, because nobody wrote down when it started. The
     * conversion lives here, next to the seconds, so every surface that answers a
     * send converts it the same way rather than each remembering to add "now".
     *
     * @return int Milliseconds since the Unix epoch when a send is allowed again
     */
    public function resendAt(): int
    {
        return TimeHelper::nowMs() + $this->resendInSeconds * TimeConstants::MS_PER_SECOND;
    }
}
