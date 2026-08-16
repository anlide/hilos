<?php

declare(strict_types=1);

namespace Hilos\Backup\Ship;

/**
 * BackupShipPlan - the one transfer the agent is to spawn next.
 *
 * Everything the agent needs to build the command and to record what came of it: which step, of
 * which backup, in which scope, from which local file. A mirror step names no backup - it is
 * about a directory as a whole - which is why the id is nullable here and nowhere else.
 */
final class BackupShipPlan
{
    /**
     * @param BackupShipStep $step What this transfer does
     * @param ?string $backupId Backup the step belongs to; null for a mirror step
     * @param string $scope Scope value naming the directory on both sides
     * @param string $localPath Absolute local path being copied: a file to push, a directory to mirror
     */
    public function __construct(
        public readonly BackupShipStep $step,
        public readonly ?string $backupId,
        public readonly string $scope,
        public readonly string $localPath,
    ) {
    }
}
