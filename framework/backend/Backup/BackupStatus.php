<?php

declare(strict_types=1);

namespace Hilos\Backup;

/**
 * BackupStatus - terminal outcome recorded in a backup metadata sidecar.
 *
 * The create path (HIL-270) writes `error` when a dump fails, so the read path
 * can still index the failed attempt as a history row.
 */
enum BackupStatus: string
{
    /** Backup completed and its archive is present. */
    case SUCCESS = 'success';

    /** Backup failed; the sidecar records the failure without a usable archive. */
    case ERROR = 'error';

    /**
     * Parses a stored status value, tolerating unknown/empty input.
     *
     * @param ?string $value Raw status value
     * @return ?self Matched status or null when unrecognized
     */
    public static function fromString(?string $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::tryFrom($value);
    }
}
