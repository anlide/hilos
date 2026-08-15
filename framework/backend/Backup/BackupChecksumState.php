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
     * Derives the standing checksum state of one stored backup from what its record carries.
     *
     * No digest wins over everything else: a row that was never hashed has nothing to have
     * been verified, whatever else it carries. Any other stored outcome than ok/mismatch (none
     * is ever written today) degrades to {@see PRESENT} - a digest exists, and nothing
     * trustworthy is known about a check of it.
     *
     * Lives on the enum rather than on the one table that shows it, because the restore gate
     * asks the same question of the same record ({@see RestoreUiGate}) and two derivations of
     * one state are one derivation waiting to disagree.
     *
     * @param ?string $sha256 Archive digest recorded in the sidecar; null when none was ever taken
     * @param ?string $verifyOutcome Recorded {@see BackupVerifyOutcome} value; null when never verified
     * @return self Checksum state the record stands in
     */
    public static function fromRecord(?string $sha256, ?string $verifyOutcome): self
    {
        if ($sha256 === null) {
            return self::NONE;
        }

        return match (BackupVerifyOutcome::fromString($verifyOutcome)) {
            BackupVerifyOutcome::OK => self::VERIFIED,
            BackupVerifyOutcome::MISMATCH => self::MISMATCH,
            default => self::PRESENT,
        };
    }

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
