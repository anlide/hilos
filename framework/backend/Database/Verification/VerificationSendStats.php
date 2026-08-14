<?php

declare(strict_types=1);

namespace Hilos\Database\Verification;

use Hilos\Auth\Verification\VerificationService;
use Hilos\Database\Object\Collection\UserVerifications;

/**
 * VerificationSendStats - what the challenge rows say about sends to one target (HIL-421).
 *
 * The single read the send gate needs, answered in one pass over the rows already
 * loaded for a (type, identifier): when a code was last issued, and how many were
 * issued inside the counting window. Both numbers come off `created_at`, so a
 * challenge that was voided, consumed, or expired still counts - issuing is what
 * limits a mailbox, not whether the code survived.
 *
 * Produced by {@see UserVerifications::sendStats()} and read by the gate inside
 * {@see VerificationService::issue()}; nothing else builds one.
 */
final class VerificationSendStats
{
    /**
     * @param ?int $lastIssuedAt Unix seconds of the newest issue, or null when nothing was ever issued
     * @param int $sentInWindow Codes issued inside the counting window
     */
    public function __construct(
        public readonly ?int $lastIssuedAt,
        public readonly int $sentInWindow,
    ) {
    }

    /**
     * Builds the stats of a target that was never mailed this template.
     *
     * @return self Stats with no last issue and an empty window
     */
    public static function never(): self
    {
        return new self(null, 0);
    }

    /**
     * @param int $lastIssuedAt Unix seconds of the newest issue
     * @param int $sentInWindow Codes issued inside the counting window
     * @return self Stats of a target that was mailed at least once
     */
    public static function issued(int $lastIssuedAt, int $sentInWindow): self
    {
        return new self($lastIssuedAt, $sentInWindow);
    }
}
