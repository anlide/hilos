<?php

declare(strict_types=1);

namespace Hilos\Backup\Ship;

/**
 * LocalBackupShipper - copies backups into a directory on this machine.
 *
 * The same rsync as {@see SshBackupShipper} without the remote half, so a mounted network share
 * needs no driver of its own: the operator mounts it and points the destination at the mount
 * point. Whether that path leaves the machine is the mount's business, not this subsystem's.
 */
final class LocalBackupShipper implements BackupShipperInterface
{
    /** Transfer binary; the same one the ssh driver uses, without a transport argument. */
    public const string BINARY = 'rsync';

    /**
     * @param BackupShipTarget $target Parsed file destination
     */
    public function __construct(
        private readonly BackupShipTarget $target,
    ) {
    }

    /**
     * @param string $localPath Absolute path of the archive or sidecar to copy
     * @param string $scope Scope value the file belongs to
     * @return BackupShipCommand Ready-to-spawn transfer command
     */
    public function pushCommand(string $localPath, string $scope): BackupShipCommand
    {
        return new BackupShipCommand(self::BINARY, [
            '-a',
            '--partial-dir=' . self::PARTIAL_DIR,
            $localPath,
            $this->destination($scope),
        ]);
    }

    /**
     * @param string $localScopeDir Absolute path of the local scope directory
     * @param string $scope Scope value being mirrored
     * @return BackupShipCommand Ready-to-spawn mirror command
     */
    public function mirrorCommand(string $localScopeDir, string $scope): BackupShipCommand
    {
        return new BackupShipCommand(self::BINARY, [
            '-r',
            '--delete',
            '--existing',
            '--ignore-existing',
            self::EXCLUDE_TEMP,
            self::EXCLUDE_PARTIAL_DIR,
            rtrim($localScopeDir, '/') . '/',
            $this->destination($scope),
        ]);
    }

    /**
     * The destination directory of one scope, with the trailing slash rsync reads as "into here".
     *
     * @param string $scope Scope value naming the directory
     * @return string Rsync destination
     */
    private function destination(string $scope): string
    {
        return sprintf('%s/%s/', $this->target->path, $scope);
    }
}
