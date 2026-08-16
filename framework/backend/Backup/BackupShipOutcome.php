<?php

declare(strict_types=1);

namespace Hilos\Backup;

/**
 * BackupShipOutcome - the result of one attempt to copy a stored backup off this machine.
 *
 * The outcome of the transfer OPERATION, the same way {@see BackupVerifyOutcome} is the
 * outcome of a verify run: what the copy state of a list row reads as afterwards belongs
 * to {@see BackupShipState}, which also has to answer for a machine that ships nowhere.
 *
 * Success is the driver's exit code and nothing more - the remote copy is never re-hashed
 * from here, because verifying it honestly is a verify-class operation on the far side of
 * the link (HIL-435) and not part of shipping.
 */
enum BackupShipOutcome: string
{
    /** The archive and its sidecar reached the destination. */
    case OK = 'ok';

    /** The transfer failed; the reason is kept beside it as a short last-error string. */
    case FAILED = 'failed';

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
