<?php

declare(strict_types=1);

namespace Hilos\Log;

use Hilos\Constants\LogRotationConstants;
use Hilos\Fs\Exception\DirectoryCreateException;
use Hilos\Fs\Exception\FileMoveException;
use Hilos\Fs\FsException;
use Hilos\Fs\FsPath;
use Hilos\Utils\Helpers\FileSystemHelper;

/**
 * Moves one rotation batch from the staging directory into the archive (HIL-870).
 *
 * Rotation itself is a rename inside the log root and stays instantaneous whatever the archive is
 * ({@see LogRotator}); this is the second step, the one that may take as long as it takes. Both
 * steps run for every installation: where the archive sits on the device of the live logs the
 * carry degenerates into renaming one directory, and where it does not the same call copies. The
 * device is never PREDICTED — the rename is attempted and its refusal is the answer — because a
 * prediction from `stat` lies on a directory it cannot measure, and two branches written from a
 * guess drift apart on the first edit.
 *
 * The batch appears in the archive WHOLE or not at all: the copying half writes into
 * `{@see LogRotationConstants::INCOMING_DIR_PREFIX}{batch}` and renames that into place only once
 * every file has arrived — a rename inside the archive, so on the far device, so O(1) again. The
 * leading dot keeps the half-arrived copy out of {@see LogRotationConstants::TIMESTAMP_DIR_NAME_PATTERN},
 * which is what keeps it out of the index the screen draws and away from the cleanup
 * ({@see LogArchivePruner}) that would otherwise delete a batch halfway here.
 *
 * Resuming is not designed for separately, because there is nothing to resume FROM: a file is
 * copied and its source removed one at a time, so an interrupted carry leaves exactly the
 * remainder in staging and the next one picks it up. The state is the content of the directories,
 * and no record of "how far it got" is written anywhere.
 *
 * Stateless and bound to a log root, the way {@see LogArchivePruner} is: it is handed a directory,
 * answers with a report, and writes no journal line of its own — the agent that asked
 * ({@see LogCarrierAgent}) does that under its own name.
 */
final class LogBatchCarrier
{
    /** Mode the carrier creates the archive and its incoming directories with. */
    private const int ARCHIVE_DIR_PERMISSIONS = 0755;

    /**
     * @param string $logDirectory Log root of this node, the directory holding the staging and archive subtrees
     */
    public function __construct(private readonly string $logDirectory)
    {
    }

    /**
     * Batches waiting in the staging directory, oldest first.
     *
     * The order is the plain string order of the names, which is chronological because
     * {@see LogRotationConstants::TIMESTAMP_FORMAT} runs from the widest unit to the narrowest.
     * Carrying them in that order is what makes the archive read in the order the rotations
     * happened.
     *
     * @return list<string> Batch directory names, oldest first, empty when there is nothing to carry
     */
    public function pendingBatchNames(): array
    {
        $stagingDirectory = $this->stagingDirectory();
        $entries = FileSystemHelper::scandirOrFalse($stagingDirectory);
        if ($entries === false) {
            return [];
        }

        $names = [];
        foreach ($entries as $name) {
            if (preg_match(LogRotationConstants::TIMESTAMP_DIR_NAME_PATTERN, $name) !== 1) {
                continue;
            }
            if (!is_dir($stagingDirectory . DIRECTORY_SEPARATOR . $name)) {
                continue;
            }
            $names[] = $name;
        }
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * Weight of everything waiting in the staging directory.
     *
     * Read off the disk rather than accumulated as batches arrive: the number is wanted when the
     * far volume has been refusing for a while, and a counter kept in memory would restart with
     * the node while the files stayed.
     *
     * @return int Summed size in bytes of the files in every staging batch, zero when there are none
     */
    public function stagingBytes(): int
    {
        $stagingDirectory = $this->stagingDirectory();

        $total = 0;
        foreach ($this->pendingBatchNames() as $batchName) {
            $batchDirectory = $stagingDirectory . DIRECTORY_SEPARATOR . $batchName;
            $entries = FileSystemHelper::scandirOrFalse($batchDirectory);
            if ($entries === false) {
                continue;
            }
            foreach ($entries as $name) {
                $path = $batchDirectory . DIRECTORY_SEPARATOR . $name;
                if (!is_file($path)) {
                    continue;
                }
                try {
                    $total += FsPath::size($path);
                } catch (FsException) {
                    // A file that will not be measured is left out of the total: this figure feeds a
                    // complaint about growth, and refusing to produce it would silence the complaint.
                    continue;
                }
            }
        }

        return $total;
    }

    /**
     * Carries one batch out of staging and into the archive.
     *
     * In this order: create the archive if it is not there, try to rename the whole batch directory
     * into it, and — only when that is refused — copy the batch file by file into the incoming
     * directory, rename that into place, and remove the emptied staging directory.
     *
     * Every refusal leaves the batch where it is and names itself in the report. That is the whole
     * failure policy: staging is on the device the node is already writing its live logs to, so a
     * batch that stays there stays readable, and the next carry tries again.
     *
     * @param string $batchName Batch directory name, as {@see pendingBatchNames()} gives it
     * @return LogCarryReport How the batch travelled, or why it did not
     */
    public function carry(string $batchName): LogCarryReport
    {
        if (preg_match(LogRotationConstants::TIMESTAMP_DIR_NAME_PATTERN, $batchName) !== 1) {
            return LogCarryReport::failed($batchName, 0, 'the name is not a rotation batch');
        }

        $source = $this->stagingDirectory() . DIRECTORY_SEPARATOR . $batchName;
        if (!is_dir($source)) {
            return LogCarryReport::failed($batchName, 0, 'the batch is no longer in the staging directory');
        }

        try {
            FsPath::ensureDirectory($this->archiveDirectory(), self::ARCHIVE_DIR_PERMISSIONS);
        } catch (DirectoryCreateException $exception) {
            return LogCarryReport::failed($batchName, 0, $exception->getMessage());
        }

        $target = $this->archiveDirectory() . DIRECTORY_SEPARATOR . $batchName;
        $incoming = $this->archiveDirectory() . DIRECTORY_SEPARATOR . LogRotationConstants::INCOMING_DIR_PREFIX . $batchName;
        if (is_dir($target)) {
            return $this->finishArrivedBatch($batchName, $source);
        }

        // A copy already begun is finished by copying, never by a rename of what is left: half the
        // batch is in the incoming directory, and renaming the remainder over it would publish a
        // batch missing everything that had already travelled.
        if (!is_dir($incoming)) {
            try {
                FsPath::move($source, $target);

                return LogCarryReport::renamedWhole($batchName);
            } catch (FileMoveException) {
                // The ordinary refusal: the archive is on another device, so this is not a rename at
                // all and the batch has to be copied. Anything else that refuses a rename here — a
                // permission, a vanished directory — reaches the same copy and reports from there.
            }
        }

        return $this->copyBatch($batchName, $source, $incoming, $target);
    }

    /**
     * @return string Archive subtree under the log root, whether or not it exists yet
     */
    public function archiveDirectory(): string
    {
        return $this->logDirectory . DIRECTORY_SEPARATOR . LogRotationConstants::LOG_ARCHIVE_SUBDIR_NAME;
    }

    /**
     * @return string Staging subtree under the log root, whether or not it exists yet
     */
    public function stagingDirectory(): string
    {
        return $this->logDirectory . DIRECTORY_SEPARATOR . LogRotationConstants::LOG_STAGING_SUBDIR_NAME;
    }

    /**
     * Copies a batch file by file into the incoming directory and publishes it with one rename.
     *
     * Each file is removed from staging the moment its copy is there, which is what makes an
     * interrupted run resumable without a record: what is left in staging IS what is left to do.
     * A file interrupted mid-copy is written again from the start, because its source outlives its
     * incomplete copy by exactly one step.
     *
     * @param string $batchName Batch directory name, for the report
     * @param string $source Staging directory of the batch
     * @param string $incoming Directory inside the archive the copies are gathered in
     * @param string $target Final directory of the batch inside the archive
     * @return LogCarryReport What arrived, or why the batch stayed in staging
     */
    private function copyBatch(string $batchName, string $source, string $incoming, string $target): LogCarryReport
    {
        try {
            FsPath::ensureDirectory($incoming, self::ARCHIVE_DIR_PERMISSIONS);
        } catch (DirectoryCreateException $exception) {
            return LogCarryReport::failed($batchName, 0, $exception->getMessage());
        }

        $entries = FileSystemHelper::scandirOrFalse($source);
        if ($entries === false) {
            return LogCarryReport::failed($batchName, 0, "the batch directory cannot be listed: {$source}");
        }

        $movedFileCount = 0;
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $source . DIRECTORY_SEPARATOR . $name;
            if (!is_file($path)) {
                // Rotation puts nothing but files in a batch, so this is somebody else's. Copying a
                // subtree is not this carrier's job, and publishing the batch without it would lose
                // whatever it is.
                return LogCarryReport::failed($batchName, $movedFileCount, "the batch holds {$name}, which is not a file");
            }

            try {
                FsPath::copy($path, $incoming . DIRECTORY_SEPARATOR . $name);
                FsPath::delete($path);
            } catch (FsException $exception) {
                return LogCarryReport::failed($batchName, $movedFileCount, $exception->getMessage());
            }
            $movedFileCount++;
        }

        try {
            FsPath::move($incoming, $target);
        } catch (FileMoveException $exception) {
            return LogCarryReport::failed($batchName, $movedFileCount, $exception->getMessage());
        }
        if (!FileSystemHelper::rmdirOrFalse($source)) {
            return LogCarryReport::failed($batchName, $movedFileCount, "the emptied staging directory cannot be removed: {$source}");
        }

        return LogCarryReport::copied($batchName, $movedFileCount);
    }

    /**
     * Clears the staging directory of a batch the archive already holds.
     *
     * Two things reach this: a carry interrupted between publishing the batch and removing what it
     * was copied from, and a genuine name collision. They are told apart by the one question worth
     * asking — is there anything left in staging — because a batch that arrived leaves an empty
     * directory behind and a collision does not. Merging the two silently is what may not happen:
     * two rotations cannot share a second (every attempt resets the age baseline, HIL-480), so a
     * non-empty collision means something is broken rather than raced.
     *
     * @param string $batchName Batch directory name, for the report
     * @param string $source Staging directory of the batch
     * @return LogCarryReport Success when the leftover directory went, a refusal when it holds files
     */
    private function finishArrivedBatch(string $batchName, string $source): LogCarryReport
    {
        $entries = FileSystemHelper::scandirOrFalse($source);
        if ($entries === false) {
            return LogCarryReport::failed($batchName, 0, "the batch directory cannot be listed: {$source}");
        }
        if (array_values(array_diff($entries, ['.', '..'])) !== []) {
            return LogCarryReport::failed($batchName, 0, 'the archive already holds a batch of that name');
        }
        if (!FileSystemHelper::rmdirOrFalse($source)) {
            return LogCarryReport::failed($batchName, 0, "the emptied staging directory cannot be removed: {$source}");
        }

        return LogCarryReport::copied($batchName, 0);
    }
}
