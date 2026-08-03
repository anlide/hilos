<?php

declare(strict_types=1);

namespace Hilos\Backup;

/**
 * BackupChecksumState - the checksum property of one backup as a list row shows it.
 *
 * Deliberately distinct from {@see BackupVerifyOutcome}: that one is the result of a
 * verify OPERATION (including cases like a missing archive, which say nothing about the
 * checksum), while this is the standing state of a stored backup - what the operator
 * reads off the Checksum column without running anything.
 *
 * The digest itself never reaches the browser; this state is all the list needs.
 */
enum BackupChecksumState: string
{
    /**
     * No digest recorded: the backup was written before checksums existed, its hashing failed,
     * or the run had too little of its timeout left to hash (the archive always outranks its
     * digest, {@see BackupCreator::create()}). Which of the three it was lives in the sidecar's
     * warnings, not here - the list only needs to know there is nothing to check against.
     */
    case NONE = 'none';

    /** A digest is recorded, but the archive has never been verified against it. */
    case PRESENT = 'present';

    /** Verified at least once, and the archive matched. */
    case VERIFIED = 'verified';

    /** Verified, and the archive did NOT match what was recorded. */
    case MISMATCH = 'mismatch';

    /**
     * Parses a stored checksum state, tolerating unknown/empty input.
     *
     * @param ?string $value Raw state value
     * @return ?self Matched state or null when unrecognized
     */
    public static function fromString(?string $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::tryFrom($value);
    }
}
