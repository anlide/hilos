<?php

declare(strict_types=1);

namespace Hilos\Auth\Verification;

use Hilos\Database\Object\Collection\UserVerifications;

/**
 * VerificationRejectReason - the closed set of reasons a consume is refused (HIL-607).
 *
 * A verification says nothing to the person about WHY it refused — that is the
 * anti-enumeration posture {@see VerificationService} is built on, and it does not
 * change here. What changed is that it now says so to the OPERATOR: a magic-link
 * click that fails left no trace at all, so the only way to answer "did the click
 * arrive, and what happened to it" was to read the table by hand.
 *
 * The set is closed and named rather than free text because these values end up in
 * a log a human greps months later: a reason spelled two ways in two call sites is
 * a reason that cannot be counted. `no_challenge` covers both "never issued" and
 * "nothing here explains it"; four of the rest each name one state a challenge row
 * can be in. Which one a refusal is comes from
 * {@see UserVerifications::describeInactive()} for a challenge that could not be
 * found, and from the service itself for one that was found and did not match.
 *
 * `race_lost` is the sixth and the odd one out: it names no state of the row but the
 * outcome of a contest for it, and it is the only reason a refusal can carry after
 * the submitted code MATCHED. It was given its own word rather than folded into
 * `consumed` (HIL-679) because counting is the point: under `consumed` a lost race
 * reads as an ordinary visit with a stale link, and a rising count of them — which
 * is a front end submitting twice, not a back end failing — would say nothing.
 */
final class VerificationRejectReason
{
    public const string NO_CHALLENGE = 'no_challenge';
    public const string EXPIRED = 'expired';
    public const string CONSUMED = 'consumed';
    public const string ATTEMPTS_EXHAUSTED = 'attempts_exhausted';
    public const string SECRET_MISMATCH = 'secret_mismatch';
    public const string RACE_LOST = 'race_lost';
}
