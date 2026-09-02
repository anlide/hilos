<?php

declare(strict_types=1);

namespace Hilos\Backup;

/**
 * BackupCeilingSpare - how much of the store one guard held back from the ceiling pass.
 */
final class BackupCeilingSpare
{
    /**
     * @param BackupCeilingGuard $guard Guard that held these rows
     * @param int $count How many rows it held
     * @param int $bytes How many bytes those rows occupy
     */
    public function __construct(
        public readonly BackupCeilingGuard $guard,
        public readonly int $count,
        public readonly int $bytes,
    ) {
    }
}
