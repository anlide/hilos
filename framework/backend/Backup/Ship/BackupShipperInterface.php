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
     * Where a push parks the part of a file that has arrived, until all of it has.
     *
     * A bare `--partial` keeps the fragment UNDER THE REAL NAME, which was harmless while a push
     * only ever created a file that was not there: no sidecar sat beside it, so nothing read it as
     * a finished copy. A push now overwrites a full copy that already has its sidecar, and an
     * interrupted one would leave a truncated archive wearing a complete passport. With a staging
     * directory the resume is kept and the real name is only ever written whole.
     */
    public const string PARTIAL_DIR = '.tmp-ship-partial';

    /**
     * Builds the command copying one local file into the destination's directory for a scope.
     *
     * @param string $localPath Absolute path of the archive or sidecar to copy
     * @param string $scope Scope value the file belongs to; names its directory on the receiver
     * @return BackupShipCommand Ready-to-spawn transfer command
     */
    public function pushCommand(string $localPath, string $scope): BackupShipCommand;

    /**
     * Builds the command deleting named pairs from the destination's scope directory.
     *
     * The deletion half of the mirror, and nothing but: what rotation and the delete action
     * removed here has to leave the receiver too, named in the include list, never "everything
     * this directory lacks". Rules are read top to bottom and the first match wins; files
     * excluded from the transfer are protected from `--delete` (there is no `--delete-excluded`),
     * so one `--exclude=*` after the includes is the whole of that protection. That is what
     * keeps unpublished store artifacts a live backup is still writing under
     * {@see BackupCreator::TEMP_PREFIX}, the resume directory {@see self::PARTIAL_DIR} a
     * concurrent push still uses, copies this node never deleted, and the marker files
     * themselves.
     *
     * It writes NOTHING. The copying half is the push steps, which the index repeats until they
     * succeed, so a mirror that also re-stated the directory would be a second, weaker copier: it
     * walks names rather than publish order, and `.json` sorts before `.tar.gz`, so an interrupted
     * pass would put a complete sidecar beside a truncated archive - the one anomaly
     * {@see BackupShipStep} says the read path never has to face. With a copy that may be
     * ciphertext of one recipient set and a receiver holding ciphertext of another, re-stating
     * would also be wrong rather than merely redundant: the two files carry the same name and
     * differ in nothing rsync's quick check looks at. `--existing` and `--ignore-existing` are
     * what hold this when a marker is already written and the local file is still there.
     *
     * @param string $localScopeDir Absolute path of the local scope directory
     * @param string $scope Scope value being mirrored; names its directory on the receiver
     * @param non-empty-list<string> $bases Base names whose pair is owed a delete on the receiver
     * @return BackupShipCommand Ready-to-spawn mirror command
     */
    public function mirrorCommand(string $localScopeDir, string $scope, array $bases): BackupShipCommand;
}
