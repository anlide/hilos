<?php

declare(strict_types=1);

namespace Hilos\Auth\SecondFactor;

/**
 * What one check of a second-factor code came to, the writes it made included (HIL-1406).
 *
 * The check is a write: a right app code takes its step, a right backup code burns, a wrong app
 * code is counted against the person and may lock their app codes. So its outcome is more than
 * yes or no - the users library acts on each part once the person's agent answers: a code that
 * missed is told to the session holder on the sign-in step, and the miss that put the lock mails
 * the person ({@see SecondFactorPersonEdits}).
 */
final readonly class SecondFactorProofCheck
{
    /**
     * @param bool $proven Whether the code proved the person
     * @param bool $missed Whether the code was checked and matched nothing; an app code refused under the lock is not
     * @param ?int $lockMisses Wrong app codes the lock was put on, when this miss put it; null otherwise
     * @param ?int $lockUntil End of that lock (unix seconds), when this miss put it; null otherwise
     * @param ?string $refusal Words the code is refused in, or null when it proved the person
     */
    public function __construct(
        public bool $proven,
        public bool $missed,
        public ?int $lockMisses,
        public ?int $lockUntil,
        public ?string $refusal,
    ) {
    }
}
