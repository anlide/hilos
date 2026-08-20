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
 * "nothing here explains it"; the other four each name one state a challenge row
 * can be in. Which one a refusal is comes from
 * {@see UserVerifications::describeInactive()} for a challenge that could not be
 * found, and from the service itself for one that was found and did not match.
 */
final class VerificationRejectReason
{
    public const string NO_CHALLENGE = 'no_challenge';
    public const string EXPIRED = 'expired';
    public const string CONSUMED = 'consumed';
    public const string ATTEMPTS_EXHAUSTED = 'attempts_exhausted';
    public const string SECRET_MISMATCH = 'secret_mismatch';
}
