<?php

declare(strict_types=1);

namespace Hilos\Backup;

/**
 * BackupVerifyOutcome - the result of verifying one archive against its recorded digest.
 *
 * This is the outcome of the verify OPERATION, not the checksum property of a stored
 * backup: an archive that carries no digest is "nothing to check" ({@see NO_DIGEST}),
 * which is deliberately not a failure - every sidecar written before HIL-435 reads back
 * without one, and turning the whole accumulated history red on release would be wrong.
 *
 * Only {@see OK} and {@see MISMATCH} are ever recorded in a sidecar: the other cases say
 * nothing about the archive that a later run would want to read back.
 */
enum BackupVerifyOutcome: string
{
    /** The archive matches the digest recorded when it was written. */
    case OK = 'ok';

    /** The archive differs from its recorded digest (or from its recorded size). */
    case MISMATCH = 'mismatch';

    /** The sidecar carries no digest: written before HIL-435, or by a run whose hashing failed. */
    case NO_DIGEST = 'no-digest';

    /** The sidecar is indexed but its archive is gone. */
    case ARCHIVE_MISSING = 'archive-missing';

    /** The archive is present but could not be read through. */
    case UNREADABLE = 'unreadable';

    /**
     * Parses a stored outcome value, tolerating unknown/empty input.
     *
     * @param ?string $value Raw outcome value
     * @return ?self Matched outcome or null when unrecognized
     */
    public static function fromString(?string $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::tryFrom($value);
    }
}
