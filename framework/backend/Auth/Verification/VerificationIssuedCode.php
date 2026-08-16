<?php

declare(strict_types=1);

namespace Hilos\Auth\Verification;

use Hilos\Auth\CodeChannel\CodeChannel;

/**
 * VerificationIssuedCode - what {@see VerificationService::issueForChannel()} answers (HIL-492).
 *
 * The pair a caller that delivers the code ITSELF needs: the send gate's verdict,
 * and - only when that verdict is "sent" - the plaintext code to hand a transport.
 * {@see VerificationService::issue()} cannot answer this shape, because it delivers
 * inside itself and therefore never has to let the code out; the code channels do
 * the opposite, probing reachability first and sending over a channel the service
 * knows nothing about, so the mint and the send are two steps for them.
 *
 * The code is null on every refused arm, which is the invariant worth stating: a
 * held or capped send mints nothing, so there is no code to carry, and a caller that
 * reads {@see code} without checking {@see VerificationSendOutcome::$sent} finds null
 * rather than a stale value from an earlier issue.
 *
 * This is the ONLY type that carries a plaintext code out of the verification layer,
 * and it exists for one consumer - the code agent, which hands it straight to a
 * {@see CodeChannel}. It is not a DTO: it never crosses a process boundary, so the
 * code never reaches the wire, a log, or the sync bus.
 */
final readonly class VerificationIssuedCode
{
    /**
     * @param VerificationSendOutcome $outcome Send-gate verdict for this issue
     * @param ?string $code Plaintext code to deliver, null on every arm that minted nothing
     */
    private function __construct(
        public VerificationSendOutcome $outcome,
        public ?string $code,
    ) {
    }

    /**
     * Builds the answer of an issue that minted a code for the caller to deliver.
     *
     * @param VerificationSendOutcome $outcome Sent verdict carrying the fresh cooldown
     * @param string $code Plaintext code to hand the channel
     * @return self Issue carrying the code
     */
    public static function minted(VerificationSendOutcome $outcome, string $code): self
    {
        return new self($outcome, $code);
    }

    /**
     * Builds the answer of an issue the send gate refused.
     *
     * @param VerificationSendOutcome $outcome Held-by-cooldown or cap-reached verdict
     * @return self Issue carrying no code
     */
    public static function refused(VerificationSendOutcome $outcome): self
    {
        return new self($outcome, null);
    }
}
