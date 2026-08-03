<?php

declare(strict_types=1);

namespace Hilos\Backup;

/**
 * BackupVerifier - checks a stored archive against the digest recorded when it was written.
 *
 * This is corruption detection, not authentication: it answers "is this still the file
 * {@see BackupCreator::create()} published?", and nothing more. It is deliberately pure -
 * no env, no runtime, no sidecar rewriting - so the operator command can hash gigabytes in
 * its own process while the monopoly agent stays free (a multi-gigabyte hash on the daemon
 * loop would block backup creation and page actions for minutes).
 *
 * The checks run cheapest-first, so a truncated archive is rejected on its size without
 * reading a byte of it, and the digest is streamed only when the size already agrees.
 */
final class BackupVerifier
{
    /**
     * Digest algorithm for archive checksums; owned here so the write path
     * ({@see BackupCreator::create()}) and this check path cannot drift apart.
     *
     * sha256 streamed with `hash_file()`, stored full (64 lowercase hex chars): archives are
     * gigabytes, and a truncated digest would trade real corruption detection for nothing.
     */
    public const string DIGEST_ALGO = 'sha256';

    /** Mismatch detail for an archive whose size already disagrees with the sidecar. */
    private const string REASON_SIZE_DIFFERS = 'size differs';

    /**
     * Verifies one archive against its sidecar metadata.
     *
     * A sidecar without a digest yields {@see BackupVerifyOutcome::NO_DIGEST} rather than a
     * failure: it predates HIL-435 (or its hashing failed), so there is nothing to check.
     *
     * @param string $archivePath Absolute path to the stored archive
     * @param BackupMetadata $metadata Sidecar metadata carrying the recorded digest and size
     * @return BackupVerifyResult What the verification concluded
     */
    public function verify(string $archivePath, BackupMetadata $metadata): BackupVerifyResult
    {
        if ($metadata->sha256 === null) {
            return new BackupVerifyResult(BackupVerifyOutcome::NO_DIGEST);
        }
        if (!is_file($archivePath)) {
            return new BackupVerifyResult(BackupVerifyOutcome::ARCHIVE_MISSING, $metadata->sha256);
        }
        // Readability is checked before any read so an unreadable archive is reported as such
        // instead of raising a warning from hash_file() on the way to the same conclusion.
        if (!is_readable($archivePath)) {
            return new BackupVerifyResult(BackupVerifyOutcome::UNREADABLE, $metadata->sha256);
        }

        $size = filesize($archivePath);
        if ($size === false) {
            return new BackupVerifyResult(BackupVerifyOutcome::UNREADABLE, $metadata->sha256);
        }
        // A size that already disagrees is a mismatch on its own: hashing gigabytes to reach
        // the same verdict would cost minutes and tell the operator nothing new.
        if ($size !== $metadata->sizeBytes) {
            return new BackupVerifyResult(
                BackupVerifyOutcome::MISMATCH,
                $metadata->sha256,
                null,
                self::REASON_SIZE_DIFFERS,
            );
        }

        $actual = hash_file(self::DIGEST_ALGO, $archivePath);
        if ($actual === false) {
            return new BackupVerifyResult(BackupVerifyOutcome::UNREADABLE, $metadata->sha256);
        }

        return new BackupVerifyResult(
            hash_equals($metadata->sha256, $actual) ? BackupVerifyOutcome::OK : BackupVerifyOutcome::MISMATCH,
            $metadata->sha256,
            $actual,
        );
    }
}
