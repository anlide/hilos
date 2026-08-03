<?php

declare(strict_types=1);

namespace Hilos\Backup;

/**
 * BackupVerifyResult - what {@see BackupVerifier} learned about one archive.
 *
 * Carries the digests alongside the outcome so the caller can print both sides of a
 * mismatch; on every other outcome the actual digest is unknown (never computed) and
 * stays null rather than being faked as an empty string.
 */
final class BackupVerifyResult
{
    /**
     * @param BackupVerifyOutcome $outcome What the verification concluded
     * @param ?string $expected Digest recorded in the sidecar; null when the sidecar carries none
     * @param ?string $actual Digest computed from the archive; null when it was not computed
     * @param ?string $reason Short human-readable detail (e.g. why a mismatch was concluded); null when
     *     the outcome speaks for itself
     */
    public function __construct(
        public readonly BackupVerifyOutcome $outcome,
        public readonly ?string $expected = null,
        public readonly ?string $actual = null,
        public readonly ?string $reason = null,
    ) {
    }
}
