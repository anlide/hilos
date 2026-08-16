<?php

declare(strict_types=1);

namespace Hilos\Backup\Ship;

use Hilos\Backup\BackupCreator;

/**
 * BackupShipperInterface - the driver seam behind which one destination kind knows how to copy.
 *
 * A driver BUILDS commands and never runs them: the agent spawns what it gets back and polls it
 * like any other child. That split is what makes a driver unit-testable by argv alone, the same
 * way the backup supervisor's own child arguments are.
 *
 * Adding a destination kind (an S3-compatible one is the expected next) means one more
 * implementation and one more scheme in {@see BackupShipperFactory}, and nothing else moves.
 */
interface BackupShipperInterface
{
    /**
     * Keeps the store's unpublished artifacts out of a mirror.
     *
     * A scope directory is not quiet while a backup is being taken: the create child writes its
     * work directory and its temp archive right there, under
     * {@see BackupCreator::TEMP_PREFIX}. Shipping owns a process slot of its own and rotation
     * raises the mirror flag before the create child is even spawned, so a mirror running beside
     * a live backup is the ordinary order of a scheduled run rather than a race - and without
     * this, every such pass would send a raw uncompressed dump across the link and then fail when
     * the temps it was copying vanished under it.
     */
    public const string EXCLUDE_TEMP = '--exclude=' . BackupCreator::TEMP_PREFIX . '*';

    /**
     * Builds the command copying one local file into the destination's directory for a scope.
     *
     * @param string $localPath Absolute path of the archive or sidecar to copy
     * @param string $scope Scope value the file belongs to; names its directory on the receiver
     * @return BackupShipCommand Ready-to-spawn transfer command
     */
    public function pushCommand(string $localPath, string $scope): BackupShipCommand;

    /**
     * Builds the command making the destination's directory for a scope match the local one.
     *
     * This is the deletion half of the mirror: what rotation and the delete action removed here
     * has to leave the receiver too, and re-stating the whole directory is how that happens
     * without keeping a list of what was deleted.
     *
     * A mirror must never leave a half-written file visible on the receiver. It re-states a whole
     * directory, so it copies files in name order rather than in publish order, and `.json` sorts
     * before `.tar.gz` - an interrupted pass that kept its partial data would put a complete
     * sidecar beside a truncated archive, which is the one anomaly {@see BackupShipStep} says the
     * read path never has to face. A push may resume a partial file; a mirror may not.
     *
     * @param string $localScopeDir Absolute path of the local scope directory
     * @param string $scope Scope value being mirrored; names its directory on the receiver
     * @return BackupShipCommand Ready-to-spawn mirror command
     */
    public function mirrorCommand(string $localScopeDir, string $scope): BackupShipCommand;
}
