<?php

declare(strict_types=1);

namespace Hilos\Backup\Ship;

use Hilos\Constants\EnvConstants;

/**
 * SshBackupShipper - copies backups to another machine with rsync over ssh.
 *
 * An external binary, exactly as the create path already shells out to mysqldump and tar: rsync
 * resumes a partial transfer and mirrors a directory in one call, which a hand-written copier
 * over a socket would have to grow on its own.
 *
 * Host-key checking is pinned on, against an explicit `known_hosts` file
 * ({@see EnvConstants::BACKUP_SHIP_SSH_KNOWN_HOSTS}) - the payload is a dump of the whole
 * database, so an unverified receiver is not a lesser evil than no copy at all. Encrypting the
 * copy ({@see BackupArchiveEncryptor}) does not relax this: it is optional, and the receiver being
 * whoever answered is a problem of its own even when what lands there cannot be read.
 */
final class SshBackupShipper implements BackupShipperInterface
{
    /** Transfer binary; also the image requirement this driver adds to a deployment. */
    public const string BINARY = 'rsync';

    /**
     * @param BackupShipTarget $target Parsed ssh destination
     * @param string $sshKey Absolute path to the private key file
     * @param string $knownHosts Absolute path to the known_hosts file the receiver is pinned against
     */
    public function __construct(
        private readonly BackupShipTarget $target,
        private readonly string $sshKey,
        private readonly string $knownHosts,
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
            '-e',
            $this->transportArgument(),
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
            '-e',
            $this->transportArgument(),
            rtrim($localScopeDir, '/') . '/',
            $this->destination($scope),
        ]);
    }

    /**
     * The `-e` transport rsync runs its remote half over.
     *
     * One argv entry rather than several, because rsync splits this value itself; it is the one
     * place in the command where the words are read by rsync and not by the kernel. That splitting
     * is also why the identity is left out entirely when none is configured, instead of passed as
     * an empty one: {@see BackupShipperFactory} lets a deployment ship under an agent-forwarded or
     * default identity, and an empty `-i` would swallow the option behind it and turn every
     * transfer into a permanent failure that reads like a credentials problem.
     *
     * @return string Ssh invocation with the pinned host file, the port, and the key if there is one
     */
    private function transportArgument(): string
    {
        $identity = $this->sshKey === '' ? [] : ['-i', $this->sshKey];

        return implode(' ', [
            'ssh',
            ...$identity,
            '-o',
            'UserKnownHostsFile=' . $this->knownHosts,
            '-o',
            'StrictHostKeyChecking=yes',
            '-p',
            (string)$this->target->port,
        ]);
    }

    /**
     * The receiver-side directory of one scope, with the trailing slash rsync reads as "into here".
     *
     * @param string $scope Scope value naming the directory
     * @return string Rsync destination
     */
    private function destination(string $scope): string
    {
        return sprintf('%s@%s:%s/%s/', $this->target->user, $this->target->host, $this->target->path, $scope);
    }
}
