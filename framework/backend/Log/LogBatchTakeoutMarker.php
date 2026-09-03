<?php

declare(strict_types=1);

namespace Hilos\Log;

use Hilos\Backup\BackupCreator;
use Hilos\Fs\Exception\FileDeleteException;
use Hilos\Fs\Exception\FileMoveException;
use Hilos\Fs\Exception\FileWriteException;
use Hilos\Fs\FsException;
use Hilos\Fs\FsPath;
use JsonException;

/**
 * The durable fact that an operator carried one rotation batch off (HIL-483).
 *
 * A marker file INSIDE the batch directory, and not a row anywhere: the fact belongs to a
 * directory on one machine. That node may be cut off from the cluster at the moment its
 * administrator confirms, and deleting the directory takes the fact away with it instead of
 * leaving an orphaned row behind. The same reasoning the backup keep pin is stored by
 * ({@see BackupCreator::setStoredKeep()}, files=truth), and the write is the same idiom: a temp
 * file beside it, then a rename, so a walk in progress never reads half a marker.
 *
 * The basename deliberately does NOT end in `.log`. The store walk globs `*.log`
 * ({@see LogStoreReader}), so a marker named otherwise is counted in no file count and weighed in
 * no batch weight — the batch an operator is looking at stays the batch that was rotated.
 *
 * The fact is withdrawable while the directory is still there ({@see self::remove()}, HIL-759):
 * a confirmation is a word about a batch, and a word said by mistake is taken back rather than
 * corrected by a second one.
 *
 * Static because a marker has no state of its own: it is addressed by the directory it lives in,
 * one call per batch per walk, the way {@see FsPath} addresses a file by its path.
 */
final class LogBatchTakeoutMarker
{
    /** Basename of the marker inside the batch directory; not `*.log`, so no walk counts it. */
    public const string FILE_NAME = '.hilos-taken.json';

    /** Marker key: Unix timestamp the confirmation was recorded at. */
    public const string takenAt = 'takenAt';

    /** Marker key: id of the user who confirmed, null when the confirmation carried no identity. */
    public const string takenBy = 'takenBy';

    /** Basename prefix of the temp file the marker is published from, in the same directory. */
    private const string TEMP_PREFIX = '.tmp-taken-';

    /**
     * Reads the confirmation stamp of one batch, if the batch carries one.
     *
     * Never raises, and that is the point: this runs for every batch of every walk, and a store
     * where one directory has become unreadable must still produce an index. A marker that is
     * missing, unreadable, not JSON, or carries no usable stamp all answer the same way — this
     * batch has not been confirmed — and the next confirmation writes the file whole.
     *
     * @param string $batchDirectory Absolute path of the rotation batch directory
     * @return ?int Unix timestamp of the confirmation, or null when the batch carries none
     */
    public static function read(string $batchDirectory): ?int
    {
        try {
            $decoded = json_decode(FsPath::read(self::pathIn($batchDirectory)), true, flags: JSON_THROW_ON_ERROR);
        } catch (FsException | JsonException) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        $takenAt = $decoded[self::takenAt] ?? null;

        return is_int($takenAt) ? $takenAt : null;
    }

    /**
     * Writes the confirmation of one batch, atomically.
     *
     * The temp file is created beside the marker rather than in the system temp directory,
     * because a rename is only atomic within one filesystem and the archive may well live on
     * another. The pid is part of its name so two confirmations racing on one node cannot
     * overwrite each other's half-written file; which of them wins the rename is immaterial,
     * both are recording the same event.
     *
     * @param string $batchDirectory Absolute path of the rotation batch directory
     * @param int $takenAt Unix timestamp to record the confirmation at
     * @param ?int $takenBy Id of the user who confirmed, or null when the action carried no identity
     * @throws FileWriteException When the marker cannot be written into the batch directory
     * @throws FileMoveException When the written marker cannot be renamed over its final name
     * @throws JsonException From {@see json_encode()} with {@see JSON_THROW_ON_ERROR}
     */
    public static function write(string $batchDirectory, int $takenAt, ?int $takenBy): void
    {
        $temporaryPath = $batchDirectory . DIRECTORY_SEPARATOR . self::TEMP_PREFIX . getmypid() . '.json';
        FsPath::write($temporaryPath, json_encode(
            [self::takenAt => $takenAt, self::takenBy => $takenBy],
            JSON_THROW_ON_ERROR,
        ));
        FsPath::publish($temporaryPath, self::pathIn($batchDirectory));
    }

    /**
     * Withdraws the confirmation of one batch, leaving the batch itself untouched (HIL-759).
     *
     * The same durable fact taken back rather than a second fact written beside it: an operator
     * who confirmed by mistake has one question about this batch — has it been carried off — and
     * two files could answer it two ways. Removing the marker returns the batch to the verdict
     * the retention rule reads for it, which the confirmation was only covering over.
     *
     * A batch that carries no marker is not an error to report: two administrators clicking in
     * turn both meant the state this leaves behind, and {@see FsPath::delete()} says so by doing
     * nothing. Atomicity needs no dance here either, unlike {@see self::write()} — a walk in
     * progress either sees the file or does not, and both are whole answers.
     *
     * @param string $batchDirectory Absolute path of the rotation batch directory
     * @throws FileDeleteException When the marker is there and cannot be removed
     */
    public static function remove(string $batchDirectory): void
    {
        FsPath::delete(self::pathIn($batchDirectory));
    }

    /**
     * Names the marker of one batch directory.
     *
     * @param string $batchDirectory Absolute path of the rotation batch directory
     * @return string Absolute path of the marker file
     */
    private static function pathIn(string $batchDirectory): string
    {
        return $batchDirectory . DIRECTORY_SEPARATOR . self::FILE_NAME;
    }
}
