<?php

declare(strict_types=1);

namespace Hilos\Backup;

use Hilos\Backup\Ship\BackupShipPlanner;

/**
 * BackupShipState - the off-machine copy property of one backup as a list row shows it.
 *
 * Shaped like {@see BackupChecksumState} and distinct from {@see BackupShipOutcome} for the
 * same reason: that one is the result of one transfer attempt, this is the standing state
 * the operator reads off the Copy column without running anything.
 *
 * {@see NONE} is the answer whenever no copy is coming - either the installation ships
 * nowhere, or the run left no archive to ship - so such a backup is not shown "waiting".
 */
enum BackupShipState: string
{
    /** Nothing will copy this backup anywhere: no destination, or no archive to send. */
    case NONE = 'none';

    /** A destination is configured and this backup has not reached it yet. */
    case PENDING = 'pending';

    /** The archive and its sidecar reached the destination. */
    case SHIPPED = 'shipped';

    /** The last attempt to copy this backup failed; it will be retried while the archive is here. */
    case FAILED = 'failed';

    /**
     * Derives the standing copy state of one stored backup from what its record carries.
     *
     * What no copy can ever reach wins over everything else, the way a missing digest wins in
     * {@see BackupChecksumState::fromRecord()}. Two records read that way: one taken with no
     * destination configured, which could not be pending for anything whatever it recorded
     * when a destination still existed; and a run that ended in {@see BackupStatus::ERROR},
     * which published no archive - {@see BackupShipPlanner} only ever picks up a successful
     * one, so calling such a row "pending" promises a transfer that is not queued anywhere.
     *
     * A recorded {@see BackupShipOutcome::OK} still needs the instant beside it to read as
     * {@see SHIPPED}: the pair is written in one step, so an outcome without its timestamp is
     * a record half-written by an older version, and "not there yet" is the safe reading of it.
     *
     * @param bool $configured Whether a shipping destination is configured at all
     * @param ?string $status Recorded {@see BackupStatus} value of the run that made the backup
     * @param ?string $shippedAt ISO-8601 instant of the last successful copy; null means never
     * @param ?string $shipOutcome Recorded {@see BackupShipOutcome} value; null when never attempted
     * @return self Copy state the record stands in
     */
    public static function fromRecord(
        bool $configured,
        ?string $status,
        ?string $shippedAt,
        ?string $shipOutcome,
    ): self {
        if (!$configured || BackupStatus::fromString($status) !== BackupStatus::SUCCESS) {
            return self::NONE;
        }

        return match (BackupShipOutcome::fromString($shipOutcome)) {
            BackupShipOutcome::OK => $shippedAt === null ? self::PENDING : self::SHIPPED,
            BackupShipOutcome::FAILED => self::FAILED,
            default => self::PENDING,
        };
    }

    /**
     * Parses a stored copy state, tolerating unknown/empty input.
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
