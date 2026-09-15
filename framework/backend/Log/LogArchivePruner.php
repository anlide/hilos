<?php

declare(strict_types=1);

namespace Hilos\Log;

use Hilos\Backup\BackupCreator;
use Hilos\Constants\LogStreamConstants;
use Hilos\Constants\LogRotationConstants;
use Hilos\Utils\Helpers\FileSystemHelper;

/**
 * Removes the rotation batches an operator has confirmed carrying off, and nothing else (HIL-382).
 *
 * It asks two questions of a batch — does the directory hold a readable takeout marker
 * ({@see LogBatchTakeoutMarker}), and has the window over it run out — and never asks the retention
 * rule ({@see LogArchiveRetentionPolicy}). The rule protects what has NOT been carried off; once it has,
 * there is nothing left to protect, and a batch brought back under protection by an edited setting
 * is still a batch that is already saved elsewhere.
 *
 * The marker is read HERE rather than taken from the caller's snapshot, because a confirmation can
 * be withdrawn between the walk that produced the snapshot and this pass, and a deletion cannot.
 *
 * Stateless: it is given a log root and told which batch timestamps to consider, and what it
 * remembers between passes is the directory itself. A pass is best-effort throughout — a file that
 * will not go leaves its batch where it is, and the next pass tries again.
 *
 * A batch directory is emptied file by file rather than swept as a subtree: the recursive removal
 * next door ({@see BackupCreator::removeDirectory()}) is deliberately NOT the model here, because a
 * backup can be taken again and a log cannot. What this pass put there — the `*.log` files, the
 * marker, and the marker's interrupted temp file (this subsystem's own leftover and not somebody's
 * file) — it removes; anything else in the directory keeps the whole directory alive.
 *
 * The order within a batch is files, then the marker, then the directory. An interrupted pass so
 * leaves a batch that is still confirmed, which the next pass finishes; removing the marker first
 * would turn the leftovers back into a batch nobody has carried off, and offer it for carrying off
 * a second time.
 */
final class LogArchivePruner
{
    /**
     * @param string $logDirectory Log root of this node, the directory holding the archive subtree
     */
    public function __construct(private readonly string $logDirectory)
    {
    }

    /**
     * Removes every batch among the given ones that carries a readable takeout marker and whose undo window has elapsed.
     *
     * @param list<int> $batchTimestamps Batch Unix timestamps to consider, ordinarily the whole archive of this node
     * @param int $undoWindowSeconds The window in seconds a confirmed batch is protected for (0 = do not wait)
     * @param int $now The Unix instant this pass is judged at
     * @return LogPruneReport What was removed, what was left alone, and what would not go
     */
    public function prune(array $batchTimestamps, int $undoWindowSeconds, int $now): LogPruneReport
    {
        $removed = [];
        $failedPaths = [];
        $keptDirNames = [];
        $unreadableMarkerDirNames = [];

        foreach ($batchTimestamps as $batchTimestamp) {
            $dirName = date(LogRotationConstants::TIMESTAMP_FORMAT, $batchTimestamp);
            // Every segment of the path is minted here, from a number and two constants, and the
            // name is then held against the pattern rotation writes: nothing that addresses a
            // deletion comes off the wire, however the timestamp reached the caller.
            if (preg_match(LogRotationConstants::TIMESTAMP_DIR_NAME_PATTERN, $dirName) !== 1) {
                continue;
            }
            $directory = $this->logDirectory . DIRECTORY_SEPARATOR . LogRotationConstants::LOG_ARCHIVE_SUBDIR_NAME
                . DIRECTORY_SEPARATOR . $dirName;
            $markerPath = $directory . DIRECTORY_SEPARATOR . LogBatchTakeoutMarker::FILE_NAME;
            if (!is_dir($directory) || !is_file($markerPath)) {
                continue;
            }

            $takenAt = LogBatchTakeoutMarker::read($directory);
            if ($takenAt === null) {
                // The file is there and says nothing usable. Left alone AND reported: read as
                // un-carried-off the batch goes back among the ones an operator is asked to carry
                // off, and carrying the same batch off twice is what silence here would cost.
                $unreadableMarkerDirNames[] = $dirName;

                continue;
            }

            if ($takenAt + $undoWindowSeconds > $now) {
                continue;
            }

            $entries = FileSystemHelper::scandirOrFalse($directory);
            if ($entries === false) {
                // A batch that cannot be listed cannot be emptied honestly, and sweeping it blind is
                // the recursive removal this class refuses: it stays, and the pass names it.
                $failedPaths[] = $directory;

                continue;
            }

            $ownFiles = [];
            $foreignFound = false;
            foreach ($entries as $name) {
                if ($name === '.' || $name === '..' || $name === LogBatchTakeoutMarker::FILE_NAME) {
                    continue;
                }
                $path = $directory . DIRECTORY_SEPARATOR . $name;
                if (is_file($path)
                    && (str_ends_with($name, LogStreamConstants::STREAM_SUFFIX)
                        || str_starts_with($name, LogBatchTakeoutMarker::TEMP_PREFIX))) {
                    $ownFiles[] = $path;

                    continue;
                }
                $foreignFound = true;
            }

            $refused = self::removeFiles($ownFiles);
            foreach ($refused as $refusedPath) {
                $failedPaths[] = $refusedPath;
            }
            // The marker goes only when the directory is about to: while anything at all is left,
            // the batch stays confirmed, which is what makes an interrupted pass safe to repeat.
            if ($foreignFound) {
                $keptDirNames[] = $dirName;

                continue;
            }
            if ($refused !== []) {
                continue;
            }
            if (!FileSystemHelper::unlinkOrFalse($markerPath)) {
                $failedPaths[] = $markerPath;

                continue;
            }
            if (!FileSystemHelper::rmdirOrFalse($directory)) {
                $failedPaths[] = $directory;

                continue;
            }

            $removed[$batchTimestamp] = $takenAt;
        }

        return new LogPruneReport($removed, $failedPaths, $keptDirNames, $unreadableMarkerDirNames);
    }

    /**
     * Removes the given files, carrying on past the ones that will not go.
     *
     * @param list<string> $paths Absolute file paths to remove
     * @return list<string> The paths that are still there afterwards
     */
    private static function removeFiles(array $paths): array
    {
        $refused = [];
        foreach ($paths as $path) {
            if (!FileSystemHelper::unlinkOrFalse($path)) {
                $refused[] = $path;
            }
        }

        return $refused;
    }
}
