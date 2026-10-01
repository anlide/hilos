<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics;

use Hilos\Fs\Exception\DirectoryCreateException;
use Hilos\Fs\Exception\DirectoryNotFoundException;
use Hilos\Fs\Exception\FileDeleteException;
use Hilos\Fs\Exception\FileMoveException;
use Hilos\Fs\Exception\FileNotFoundException;
use Hilos\Fs\Exception\FilePermissionException;
use Hilos\Fs\Exception\FileReadException;
use Hilos\Fs\Exception\FileWriteException;
use Hilos\Fs\FsPath;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * The file half of a node's analytics journal: one subdirectory, its open file and its ready files (HIL-1154).
 *
 * Owned by exactly one process - the journal agent of the node ({@see AnalyticsJournalAgent}) - and
 * so it keeps the state of the open file in memory and never locks anything. The clock is handed
 * in by the caller, so what is due does not depend on when the loop got round to asking, and a
 * test drives it without waiting.
 *
 * Lines are written into the open file as they arrive; from there they are the operating
 * system's, and a crash of the process loses none of them. They reach the disk itself once a
 * second when anything was written, and when the file is rotated: a crash of the machine loses
 * up to a second of the node's events, by the owner's decision. The file rotates at
 * {@see self::ROTATE_BYTES} or {@see self::ROTATE_AGE_MS} after it opened, whichever comes
 * first, and an empty file is never opened, so it is never rotated either.
 *
 * Names: the open file is `<number, 12 digits>-<16 hex>.open`, the ready one the same stem with
 * `.jsonl`. The number is the order (the largest on disk plus one); the random tail
 * (`RandomHelper::hex()`, the tolerant axis - it only has to not collide) keeps a name unique
 * after a freeze emptied the directory and the numbers started over, while the writer still
 * remembers the files it loaded under the old ones. A name from the wire is checked against the
 * pattern before it becomes a path: only this class turns a name into a path.
 */
final class AnalyticsJournalDirectory
{
    public const int ROTATE_BYTES = 1048576;
    public const int ROTATE_AGE_MS = 10000;
    public const int SYNC_INTERVAL_MS = 1000;
    public const int PORTION_BYTES = 131072;

    /** @var string A ready file's name */
    public const string READY_NAME_PATTERN = '/^\d{12}-[0-9a-f]{16}\.jsonl$/';

    private const string OPEN_NAME_PATTERN = '/^\d{12}-[0-9a-f]{16}\.open$/';
    private const string OPEN_SUFFIX = '.open';
    private const string READY_SUFFIX = '.jsonl';
    private const string SEQUENCE_FORMAT = '%012d';
    private const int NAME_TAIL_BYTES = 8;
    private const int DIRECTORY_MODE = 0700;
    private const int FILE_MODE = 0600;
    private const string LINE_BREAK = "\n";

    /** @var ?string Stem of the open file, null while none is open */
    private ?string $openStem = null;

    /** @var int Moment the open file was opened, in milliseconds */
    private int $openedAtMs = 0;

    /** @var int Bytes written into the open file, its header included */
    private int $openBytes = 0;

    /** @var bool Whether the open file holds lines not yet synced to the disk */
    private bool $unsynced = false;

    /** @var int Moment of the last sync, in milliseconds */
    private int $lastSyncAtMs = 0;

    /** @var int Number the next opened file gets */
    private int $nextSequence = 1;

    /**
     * @param string $path Absolute path of the subdirectory this journal owns
     * @param string $node Cluster node id the files' header names, '' outside a cluster
     */
    public function __construct(
        private readonly string $path,
        private readonly string $node,
    ) {
    }

    /**
     * Whether a name read from the wire names a ready file.
     *
     * @param string $file Name to check
     * @return bool True when the name has the shape of a ready file
     */
    public static function isReadyName(string $file): bool
    {
        return preg_match(self::READY_NAME_PATTERN, $file) === 1;
    }

    /**
     * Creates the subdirectory, closes the file a previous life left open as ready, and continues the numbering.
     *
     * The left-over file may end in half a line when the machine fell; the writer passes it over.
     *
     * @return int Files a previous life left open and this start closed
     * @throws DirectoryCreateException When the subdirectory cannot be created
     * @throws DirectoryNotFoundException When the subdirectory vanished between creating and listing it
     * @throws FileReadException When the subdirectory cannot be listed
     * @throws FileMoveException When a left-over file cannot be closed
     * @throws FilePermissionException When the subdirectory's mode cannot be set
     */
    public function start(): int
    {
        FsPath::ensureDirectory($this->path, self::DIRECTORY_MODE);
        FsPath::chmod($this->path, self::DIRECTORY_MODE);

        $closed = 0;
        $largest = 0;
        foreach (FsPath::entries($this->path) as $entry) {
            if (preg_match(self::OPEN_NAME_PATTERN, $entry) === 1) {
                $stem = substr($entry, 0, -strlen(self::OPEN_SUFFIX));
                FsPath::move($this->filePath($entry), $this->filePath($stem . self::READY_SUFFIX));
                $closed++;
            } elseif (!self::isReadyName($entry)) {
                continue;
            }

            $largest = max($largest, (int)substr($entry, 0, strspn($entry, '0123456789')));
        }

        $this->nextSequence = $largest + 1;

        return $closed;
    }

    /**
     * Writes lines into the open file, opening one first when none is, and rotates it once it is full.
     *
     * @param list<string> $lines Lines without line breaks; none may be empty or hold a line break
     * @param int $nowMs Moment of the write, in milliseconds
     * @return ?string Name of the file this write made ready, or null when none was
     * @throws FileWriteException When the file cannot be opened, written or synced
     * @throws FilePermissionException When a new file's mode cannot be set
     * @throws FileNotFoundException When the open file vanished before its sync
     * @throws FileReadException When the open file cannot be opened for its sync
     * @throws FileMoveException When the full file cannot be made ready
     */
    public function append(array $lines, int $nowMs): ?string
    {
        if ($lines === []) {
            return null;
        }

        if ($this->openStem === null) {
            $this->open($nowMs);
        }

        $data = implode(self::LINE_BREAK, $lines) . self::LINE_BREAK;
        FsPath::append($this->openPath(), $data);
        $this->openBytes += strlen($data);
        $this->unsynced = true;

        return $this->openBytes >= self::ROTATE_BYTES ? $this->rotate($nowMs) : null;
    }

    /**
     * Syncs the open file once a second when it was written to, and rotates it once it is old enough.
     *
     * @param int $nowMs Moment of this tick, in milliseconds
     * @return ?string Name of the file this tick made ready, or null when none was
     * @throws FileWriteException When the sync fails
     * @throws FileNotFoundException When the open file vanished
     * @throws FileReadException When the open file cannot be opened for its sync
     * @throws FileMoveException When the old file cannot be made ready
     */
    public function tick(int $nowMs): ?string
    {
        if ($this->openStem === null) {
            return null;
        }

        if ($nowMs - $this->openedAtMs >= self::ROTATE_AGE_MS) {
            return $this->rotate($nowMs);
        }

        if ($this->unsynced && $nowMs - $this->lastSyncAtMs >= self::SYNC_INTERVAL_MS) {
            $this->sync($nowMs);
        }

        return null;
    }

    /**
     * Syncs and closes the open file and makes it ready; nothing when none is open.
     *
     * @param int $nowMs Moment of the rotation, in milliseconds
     * @return ?string Name of the ready file, or null when no file was open
     * @throws FileWriteException When the sync fails
     * @throws FileNotFoundException When the open file vanished
     * @throws FileReadException When the open file cannot be opened for its sync
     * @throws FileMoveException When the file cannot be renamed
     */
    public function rotate(int $nowMs): ?string
    {
        if ($this->openStem === null) {
            return null;
        }

        $this->sync($nowMs);
        $ready = $this->openStem . self::READY_SUFFIX;
        FsPath::move($this->openPath(), $this->filePath($ready));
        $this->openStem = null;
        $this->openBytes = 0;

        return $ready;
    }

    /**
     * The ready file the writer should load next: the one with the smallest number.
     *
     * @return ?string Its name, or null when the node has no ready file
     * @throws DirectoryNotFoundException When the subdirectory is not there
     * @throws FileReadException When the subdirectory cannot be listed
     */
    public function oldestReady(): ?string
    {
        $ready = array_values(array_filter(FsPath::entries($this->path), self::isReadyName(...)));
        sort($ready, SORT_STRING);

        return $ready[0] ?? null;
    }

    /**
     * Reads whole lines of a ready file from an offset, up to {@see self::PORTION_BYTES}.
     *
     * A line longer than a portion comes whole, alone. The tail a machine crash left - the last
     * line without its line break - comes as a line too, for the writer to pass over.
     *
     * @param string $file Name of a ready file
     * @param int $offset Byte offset to read from
     * @return ?AnalyticsJournalPortion The lines, or null when no such ready file is there
     * @throws FileReadException When the file cannot be opened
     */
    public function readPortion(string $file, int $offset): ?AnalyticsJournalPortion
    {
        $path = $this->filePath($file);
        if (!self::isReadyName($file) || !is_file($path)) {
            return null;
        }

        try {
            return FsPath::readWith($path, fn($handle): AnalyticsJournalPortion => $this->readLines($handle, $offset));
        } catch (FileNotFoundException) {
            return null;
        }
    }

    /**
     * Deletes a ready file the writer loaded; a file already gone, or a name that is no ready file's, is left be.
     *
     * @param string $file Name of a ready file
     * @throws FileDeleteException When the file stays
     */
    public function delete(string $file): void
    {
        if (!self::isReadyName($file)) {
            return;
        }

        FsPath::delete($this->filePath($file));
    }

    /**
     * Throws the whole journal away - the open file and every ready one - and forgets the open file.
     *
     * @return int Files deleted
     * @throws DirectoryNotFoundException When the subdirectory is not there
     * @throws FileReadException When the subdirectory cannot be listed
     * @throws FileDeleteException When a file stays
     */
    public function discardAll(): int
    {
        $this->openStem = null;
        $this->openBytes = 0;
        $this->unsynced = false;

        $deleted = 0;
        foreach (FsPath::entries($this->path) as $entry) {
            if (preg_match(self::OPEN_NAME_PATTERN, $entry) !== 1 && !self::isReadyName($entry)) {
                continue;
            }

            FsPath::delete($this->filePath($entry));
            $deleted++;
        }

        return $deleted;
    }

    /**
     * Opens a new file and writes its header.
     *
     * @param int $nowMs Moment of the opening, in milliseconds
     * @throws FileWriteException When the file cannot be written
     * @throws FilePermissionException When its mode cannot be set
     */
    private function open(int $nowMs): void
    {
        $stem = sprintf(self::SEQUENCE_FORMAT, $this->nextSequence) . '-' . RandomHelper::hex(self::NAME_TAIL_BYTES);
        $header = (string)AnalyticsJournalRecord::encode(AnalyticsJournalRecord::journal($this->node, $nowMs)) . self::LINE_BREAK;
        $path = $this->filePath($stem . self::OPEN_SUFFIX);

        FsPath::write($path, $header);
        FsPath::chmod($path, self::FILE_MODE);

        $this->nextSequence++;
        $this->openStem = $stem;
        $this->openedAtMs = $nowMs;
        $this->openBytes = strlen($header);
        $this->unsynced = true;
    }

    /**
     * Hands what was written into the open file to the disk.
     *
     * The seam opens the file for reading and that is enough: a sync flushes the file, whichever
     * descriptor asks for it.
     *
     * @param int $nowMs Moment of the sync, in milliseconds
     * @throws FileWriteException When the disk refuses the sync
     * @throws FileNotFoundException When the open file vanished
     * @throws FileReadException When the open file cannot be opened
     */
    private function sync(int $nowMs): void
    {
        if ($this->unsynced && !FsPath::readWith($this->openPath(), static fn($handle): bool => fsync($handle))) {
            throw new FileWriteException('Cannot sync file: ' . $this->openPath());
        }

        $this->unsynced = false;
        $this->lastSyncAtMs = $nowMs;
    }

    /**
     * Reads whole lines from the handle of a ready file.
     *
     * @param resource $handle Handle the seam opened for reading
     * @param int $offset Byte offset to read from
     * @return AnalyticsJournalPortion The lines read
     */
    private function readLines($handle, int $offset): AnalyticsJournalPortion
    {
        $size = (int)(fstat($handle)['size'] ?? 0);
        if ($offset >= $size) {
            return new AnalyticsJournalPortion([], $size, true);
        }

        fseek($handle, $offset);
        $chunk = (string)fread($handle, self::PORTION_BYTES);
        $end = strrpos($chunk, self::LINE_BREAK);
        while ($end === false && $offset + strlen($chunk) < $size) {
            // One line longer than a portion: read on to its end, and hand it over whole and alone.
            $more = (string)fread($handle, self::PORTION_BYTES);
            if ($more === '') {
                break;
            }

            $breakAt = strpos($more, self::LINE_BREAK);
            $end = $breakAt === false ? false : strlen($chunk) + $breakAt;
            $chunk .= $more;
        }

        // No line break before the end of the file: the half line a machine crash left.
        $body = $end === false ? $chunk : substr($chunk, 0, $end);
        $nextOffset = $offset + ($end === false ? strlen($chunk) : $end + 1);

        return new AnalyticsJournalPortion(
            explode(self::LINE_BREAK, $body),
            $nextOffset,
            $nextOffset >= $size,
        );
    }

    /**
     * @return string Path of the open file
     */
    private function openPath(): string
    {
        return $this->filePath($this->openStem . self::OPEN_SUFFIX);
    }

    /**
     * @param string $name File name inside the subdirectory
     * @return string Its path
     */
    private function filePath(string $name): string
    {
        return $this->path . '/' . $name;
    }
}
