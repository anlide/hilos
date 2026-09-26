<?php

declare(strict_types=1);

namespace Hilos\Log;

use DateTimeImmutable;
use Generator;
use Hilos\Fs\FsException;
use Hilos\Fs\FsPath;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Utils\Logger;

/**
 * Stateless read primitive over one selected daemon log file (HIL-384).
 *
 * Companion to {@see LogStoreReader}: where that enumerates the store, this reads the lines of a single
 * chosen file with cursor pagination and level/substring filtering, feeding the log viewer (HIL-388)
 * and live-tail (HIL-389) pages from the worker, off the master loop. Like the store reader it holds no
 * mutable state: it is bound to the log root and the store's stream classification, and each
 * {@see read()} does a fresh filesystem read (it reads the filesystem, not a `DbCollection`, so the
 * no-repository-service rule does not apply).
 *
 * Two independent scans share one line classifier: {@see LogReadQuery::ANCHOR_HEAD} walks forward from
 * the cursor a line at a time; {@see LogReadQuery::ANCHOR_TAIL} grows a byte window backward from the end
 * in {@see CHUNK_SIZE} steps until it holds one match more than the page was asked for, so large files are
 * never loaded whole for the common tail case. The extra match is what answers "is there an older page":
 * bytes before the page can be all non-matching under a filter, so only a found match proves one remains.
 * A query may cap that growth ({@see LogReadQuery::$maxWindowBytes}): a scan stopped by its ceiling has not
 * looked past it and answers "maybe" from the window's boundary instead (HIL-868).
 * Level detection is per line: every line of a stream {@see LogStoreReader::isErrorStream()} recognizes
 * is ERROR; elsewhere a recognized prefix (`[ERROR]`/`ERROR:` and the like, or the
 * `agentId|level|message` agent-pipe format under {@see Logger::AGENT_LOG_MARKER}) updates a running
 * level, and a line without one is a continuation that inherits it. The running level resets to
 * {@see Logger::LEVEL_INFO} unless the caller carries it over in {@see LogReadQuery::$inheritedLevel}
 * or a read opening on a continuation finds its entry head at most one {@see CHUNK_SIZE} step behind
 * the cut (HIL-1025).
 *
 * A forward read serves the live tail too (HIL-389) and is therefore append-aware: a trailing line with no
 * newline yet is half-written, so it is neither returned nor counted into {@see LogLinePage::$endCursor} —
 * the next read picks it up whole once the writer has finished it.
 *
 * A missing/unreadable file, or a path escaping the log root via {@see realpath()} validation, yields
 * {@see LogLinePage::unavailable()} rather than a fatal. Every answer the reader gives about a file is
 * taken from the OS live rather than from this process's stat cache: the log file is written by the master
 * while this worker reads it, so the growth of a followed file would otherwise be invisible here (HIL-874).
 */
final class LogLineReader
{
    /**
     * Matches the `[YYYY-MM-DD HH:MM:SS.mmm] ` prefix a fresh log entry starts with.
     *
     * Public because the same prefix is what tells a fresh entry from a continuation for anyone
     * reading these files, not just for this scan: {@see LogRecentTailReader} takes the entry's text
     * apart after it (HIL-867). A second copy of the pattern would be a second answer to "what is an entry".
     */
    public const string TIMESTAMP_PREFIX_PATTERN = '/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}\] /';

    /** Window growth step (bytes) for the backward tail scan and the anchor search. */
    private const int CHUNK_SIZE = 65536;

    /** Format of the timestamp {@see TIMESTAMP_PREFIX_PATTERN} matches, with milliseconds. */
    private const string TIMESTAMP_FORMAT = 'Y-m-d H:i:s.v';

    /** Format printing a parsed timestamp back as unix milliseconds. */
    private const string UNIX_MILLISECONDS_FORMAT = 'Uv';

    /**
     * Recognized new-entry level prefixes, tested in order against the text after the `[timestamp] `.
     *
     * Covers both the `[LEVEL]` form (show-log-level mode) and the `LEVEL:` form (default mode); a
     * timestamped line matching none of these is a fresh INFO entry.
     *
     * @var array<string, string> Prefix → {@see Logger} `LEVEL_*` value
     */
    private const array LEVEL_PREFIXES = [
        '[' . Logger::LEVEL_ERROR . '] ' => Logger::LEVEL_ERROR,
        Logger::LEVEL_ERROR . ': ' => Logger::LEVEL_ERROR,
        '[' . Logger::LEVEL_WARNING . '] ' => Logger::LEVEL_WARNING,
        Logger::LEVEL_WARNING . ': ' => Logger::LEVEL_WARNING,
        '[' . Logger::LEVEL_DEBUG . '] ' => Logger::LEVEL_DEBUG,
        Logger::LEVEL_DEBUG . ': ' => Logger::LEVEL_DEBUG,
        '[' . Logger::LEVEL_INFO . '] ' => Logger::LEVEL_INFO,
    ];

    /**
     * @param ?string $logDirectory Log root holding the live `*.log` files and the archive subtree, or null when it could not be resolved
     * @param ?LogStoreReader $store Store reader naming which streams carry only errors, or null when none are known
     */
    public function __construct(
        private readonly ?string $logDirectory,
        private readonly ?LogStoreReader $store = null,
    ) {
    }

    /**
     * Build a reader over the daemon log root (the directory of `DAEMON_LOG_FILE`).
     *
     * A missing env value yields a reader whose {@see read()} returns {@see LogLinePage::unavailable()},
     * mirroring {@see LogStoreReader::fromEnv()} rather than raising.
     *
     * @return self Reader bound to the configured log directory, or an unresolved reader
     */
    public static function fromEnv(): self
    {
        $store = LogStoreReader::fromEnv();

        return new self($store->logDirectory(), $store);
    }

    /**
     * Read one page of lines from a log file under the log root.
     *
     * @param string $relativePath Path of the target file relative to the log root (e.g.
     *     `worker-1.log` or `archive/2026-03-23-19-01-20/worker-1.log`)
     * @param LogReadQuery $query Anchor, cursor, limit and filters for this page
     *
     * @return LogLinePage Matched lines plus the adjacent-page cursor, or {@see LogLinePage::unavailable()}
     *                     when the file is missing/unreadable or the path escapes the log root
     */
    public function read(string $relativePath, LogReadQuery $query): LogLinePage
    {
        $path = $this->resolveReadablePath($relativePath);
        if ($path === null) {
            return LogLinePage::unavailable();
        }

        $limit = max(1, $query->limit);
        $errorStream = $this->store !== null && $this->store->isErrorStream(basename($relativePath));

        return $query->anchor === LogReadQuery::ANCHOR_TAIL
            ? $this->readTail($path, $query, $limit, $errorStream)
            : $this->readHead($path, $query, $limit, $errorStream);
    }

    /**
     * Size in bytes of a log file under the log root.
     *
     * The live tail needs both a starting position and a way to see the file grow, and the log root is
     * private to this reader — a caller reaching for `filesize()` itself would leave the traversal guard
     * of {@see resolveReadablePath()} standing aside (HIL-389).
     *
     * Measured by PATH on purpose, unlike the reads, which take the size of the file they hold open: the
     * follower has to see the file now under this name to notice a rotation (a size below its offset).
     *
     * @param string $relativePath Path of the target file relative to the log root
     *
     * @return ?int Size in bytes, or null when the path is unresolved, escapes the log root, or is not a readable file
     */
    public function size(string $relativePath): ?int
    {
        $path = $this->resolveReadablePath($relativePath);
        if ($path === null) {
            return null;
        }

        try {
            return FsPath::size($path);
        } catch (FsException) {
            return null;
        }
    }

    /**
     * Read the stamp a line opens with as unix milliseconds.
     *
     * The stamp names no zone — the file carries the local time of the node that wrote it — so it is
     * resolved in the timezone of this process, which is right exactly because the reader runs on that
     * node: the one place the file can be opened at all. Public for the reason
     * {@see TIMESTAMP_PREFIX_PATTERN} is: "when was this line written" has one answer, and both the
     * recent-failures reader ({@see LogRecentTailReader}) and the anchor search ({@see locate()}) ask it
     * (HIL-868).
     *
     * @param string $text Line text as read from the file
     *
     * @return ?int Unix milliseconds, or null when the line does not open with a stamp
     */
    public static function stampMilliseconds(string $text): ?int
    {
        if (preg_match(self::TIMESTAMP_PREFIX_PATTERN, $text, $match) !== 1) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat(self::TIMESTAMP_FORMAT, substr($match[0], 1, -2));

        return $parsed === false ? null : (int)$parsed->format(self::UNIX_MILLISECONDS_FORMAT);
    }

    /**
     * Take the level prefix off the text of an entry, leaving what the entry says (HIL-868).
     *
     * What counts as a prefix, in either form — `[WARNING] ` and `WARNING: ` — is the table the level of a line is
     * read off ({@see LEVEL_PREFIXES}), so a reader that shows the level on its own does not show it twice. Public
     * for the reason {@see TIMESTAMP_PREFIX_PATTERN} is: "where does the text of an entry begin" has one answer, and
     * the recent-failures reader ({@see LogRecentTailReader}) asks it.
     *
     * @param string $afterTimestamp Text following the `[timestamp] ` prefix
     *
     * @return string The same text without its level prefix, or unchanged when it opens with none
     */
    public static function textAfterLevel(string $afterTimestamp): string
    {
        foreach (array_keys(self::LEVEL_PREFIXES) as $prefix) {
            if (str_starts_with($afterTimestamp, $prefix)) {
                return substr($afterTimestamp, strlen($prefix));
            }
        }

        return $afterTimestamp;
    }

    /**
     * Find where in a log file the entry written at a given moment begins (HIL-868).
     *
     * Walks back from the end of the file one {@see CHUNK_SIZE} chunk at a time, newest line first, looking for
     * the last stamped line earlier than `$atMs`; the place is the stamped line right after it. The walk stops
     * at that boundary — nothing further back can move the answer — or at the start of the file, or once it
     * has gone `$maxWindowBytes` back. Each chunk is scanned once, so the search costs the distance to the
     * place rather than that distance squared, which over the megabytes an anchored read allows is the
     * difference between a click and a stall.
     *
     * A place is claimed only where it is proven. Past a boundary, the first line not earlier than the moment
     * is the place. With no boundary in reach, only a line stamped exactly `$atMs` is: a later one could be
     * the first line of a file that rotation started after the entry had left, or a line written long after
     * a place that lies beyond the ceiling, and opening the viewer there would claim a place that is not it.
     * Lines without a stamp — continuations, what PHP printed past the Logger — are stepped over, since
     * nothing orders them.
     *
     * @param string $relativePath Path of the target file relative to the log root
     * @param int $atMs Moment the entry was written, unix milliseconds
     * @param int $maxWindowBytes Furthest the search reads back from the end of the file, in bytes
     *
     * @return ?int Byte offset of the line the entry begins on, or null when the file is unreadable or outside
     *     the log root, every line is older than `$atMs`, or the place is not proven within the ceiling
     */
    public function locate(string $relativePath, int $atMs, int $maxWindowBytes): ?int
    {
        $path = $this->resolveReadablePath($relativePath);
        if ($path === null) {
            return null;
        }

        try {
            return FsPath::readWith($path, static function ($handle) use ($atMs, $maxWindowBytes): ?int {
                $fileSize = self::openFileSize($handle);
                if ($fileSize === null || $fileSize === 0) {
                    return null;
                }

                $floor = max(0, $fileSize - max(1, $maxWindowBytes));
                $chunkStart = $fileSize;
                $unscannedEnd = $fileSize;
                $placeOffset = null;
                $placeStamp = null;
                while (true) {
                    $chunkStart = max($floor, $chunkStart - self::CHUNK_SIZE);
                    fseek($handle, $chunkStart);
                    $buffer = fread($handle, $unscannedEnd - $chunkStart);
                    if ($buffer === false) {
                        return null;
                    }

                    // Newest line first: the first line earlier than the moment is the boundary, and the place
                    // is the line walked just before reaching it.
                    foreach (array_reverse(iterator_to_array(self::stampedLines($buffer, $chunkStart)), true) as $offset => $stamp) {
                        if ($stamp < $atMs) {
                            return $placeOffset;
                        }
                        $placeOffset = $offset;
                        $placeStamp = $stamp;
                    }

                    if ($chunkStart === $floor) {
                        return $placeStamp === $atMs ? $placeOffset : null;
                    }

                    // The fragment in front of the chunk's first whole line is read again, whole, with the next chunk.
                    $firstNewline = strpos($buffer, "\n");
                    if ($firstNewline !== false) {
                        $unscannedEnd = $chunkStart + $firstNewline + 1;
                    }
                }
            });
        } catch (FsException) {
            return null;
        }
    }

    /**
     * Validate that a relative path resolves to a readable file inside the log root.
     *
     * The traversal guard is `realpath()`-based: both the root and the candidate are canonicalized and
     * the candidate must sit under the root, defeating `..` escapes and symlinks pointing outside.
     *
     * The stat cache is dropped for the canonical path before anything is asked about the file (HIL-874).
     * PHP caches the OS answer per path and refreshes it only on calls that change the file from this same
     * process — but a daemon log file is appended to by the master, which harvests the line from the
     * worker's output ({@see WorkerServer}), so a worker that asked once is served its own first answer and
     * watches the file stand still while it grows. {@see size()} is where that is felt: the live tail
     * compares it against the follower's position each round, and a stale one holds the tail a tick or two
     * behind. A {@see read()} happens to escape it today only because opening the file drops the cache as a
     * side effect — an implementation detail of the engine, not a contract, so the flush sits on the entry
     * both public calls share rather than inside `size()` alone. Exactly one path is flushed, not the whole
     * cache: agents, the ORM and `Fs\Watch` share this process, and their cached answers are not ours to
     * pay with. The flush sits after the root check so a path that escaped the log root is refused without
     * a single extra stat.
     *
     * @param string $relativePath Path relative to the log root
     *
     * @return ?string Canonical absolute file path, or null when unresolved, outside the root or not a readable file
     */
    private function resolveReadablePath(string $relativePath): ?string
    {
        if ($this->logDirectory === null) {
            return null;
        }

        $realRoot = realpath($this->logDirectory);
        if ($realRoot === false) {
            return null;
        }

        $real = realpath($this->logDirectory . DIRECTORY_SEPARATOR . $relativePath);
        if ($real === false) {
            return null;
        }
        if (!str_starts_with($real, $realRoot . DIRECTORY_SEPARATOR)) {
            return null;
        }

        clearstatcache(true, $real);
        if (!is_file($real) || !is_readable($real)) {
            return null;
        }

        return $real;
    }

    /**
     * Scan forward from the cursor, collecting up to `$limit` matched lines.
     *
     * Stops before a trailing line that carries no newline: the writer is still appending it, and handing
     * out half of it now would deliver the other half as a separate line on the next read.
     *
     * @param string $path Canonical file path
     * @param LogReadQuery $query Query providing the cursor, the inherited level and the filters
     * @param int $limit Positive page size
     * @param bool $errorStream Whether every line of the file carries the ERROR level
     *
     * @return LogLinePage Matched lines in file order plus the next forward cursor and the read's end position
     */
    private function readHead(string $path, LogReadQuery $query, int $limit, bool $errorStream): LogLinePage
    {
        try {
            return FsPath::readWith($path, static function ($handle) use ($query, $limit, $errorStream): LogLinePage {
                $fileSize = self::openFileSize($handle);
                $start = max(0, $query->cursor ?? 0);
                $currentLevel = $errorStream ? Logger::LEVEL_ERROR : ($query->inheritedLevel ?? Logger::LEVEL_INFO);
                if ($fileSize === null || $start >= $fileSize) {
                    return new LogLinePage(true, [], null, false, $start, $query->inheritedLevel);
                }
                fseek($handle, $start);

                $lines = [];
                $nextCursor = null;
                $hasMore = false;
                $endCursor = $start;
                $endLevel = $query->inheritedLevel;
                $first = true;
                while (($raw = fgets($handle)) !== false) {
                    if (!str_ends_with($raw, "\n")) {
                        break;
                    }
                    $text = rtrim($raw, "\r\n");
                    if ($first) {
                        $first = false;
                        if ($start > 0
                            && $query->inheritedLevel === null
                            && !$errorStream
                            && self::isContinuation($text)
                        ) {
                            $currentLevel = self::entryLevelBefore($handle, $start) ?? Logger::LEVEL_INFO;
                        }
                    }
                    [$currentLevel, $isContinuation] = self::classify($text, $currentLevel, $errorStream);
                    $endCursor += strlen($raw);
                    $endLevel = $currentLevel;
                    if (self::passesFilter($text, $currentLevel, $query)) {
                        $lines[] = new LogLine($text, $currentLevel, $isContinuation);
                        if (count($lines) === $limit) {
                            $hasMore = $endCursor < $fileSize;
                            $nextCursor = $hasMore ? $endCursor : null;
                            break;
                        }
                    }
                }

                return new LogLinePage(true, $lines, $nextCursor, $hasMore, $endCursor, $endLevel);
            });
        } catch (FsException) {
            return LogLinePage::unavailable();
        }
    }

    /**
     * Grow a window backward from the cursor until it holds more than `$limit` matches (or reaches the start).
     *
     * One match beyond the page is what {@see tailPageFromMatches()} reads the "older page remains" answer off,
     * so the window keeps growing past a full page until either that match turns up or the file runs out on the left.
     * A third reason to stop is the query's {@see LogReadQuery::$maxWindowBytes}, and it sits beside the other two
     * rather than replacing either (HIL-868): a window that holds the extra match still answers by it, and only a
     * window that reaches the ceiling without one answers from its boundary ({@see tailPageAtCeiling()}).
     *
     * @param string $path Canonical file path
     * @param LogReadQuery $query Query providing the cursor and filters
     * @param int $limit Positive page size
     * @param bool $errorStream Whether every line of the file carries the ERROR level
     *
     * @return LogLinePage The last `$limit` matched lines in file order plus the next backward cursor
     */
    private function readTail(string $path, LogReadQuery $query, int $limit, bool $errorStream): LogLinePage
    {
        try {
            return FsPath::readWith($path, static function ($handle) use ($query, $limit, $errorStream): LogLinePage {
                $fileSize = self::openFileSize($handle);
                if ($fileSize === null) {
                    return LogLinePage::unavailable();
                }

                $end = $query->cursor ?? $fileSize;
                $end = max(0, min($end, $fileSize));
                if ($end === 0) {
                    return new LogLinePage(true, [], null, false);
                }

                $ceiling = $query->maxWindowBytes === null ? $end : min($end, max(1, $query->maxWindowBytes));
                $windowSize = 0;
                while (true) {
                    $windowSize = min($ceiling, $windowSize + self::CHUNK_SIZE);
                    $windowStart = $end - $windowSize;
                    fseek($handle, $windowStart);
                    $buffer = fread($handle, $windowSize);
                    if ($buffer === false) {
                        return LogLinePage::unavailable();
                    }

                    $startLevel = $errorStream ? Logger::LEVEL_ERROR : Logger::LEVEL_INFO;
                    if (!$errorStream && $windowStart > 0 && $query->maxWindowBytes === null) {
                        $firstNewline = strpos($buffer, "\n");
                        $firstWholePosition = $firstNewline === false ? strlen($buffer) : $firstNewline + 1;
                        if ($firstWholePosition < strlen($buffer)) {
                            $nextNewline = strpos($buffer, "\n", $firstWholePosition);
                            $firstWholeEnd = $nextNewline === false ? strlen($buffer) : $nextNewline + 1;
                            $firstWholeText = rtrim(
                                substr($buffer, $firstWholePosition, $firstWholeEnd - $firstWholePosition),
                                "\r\n",
                            );
                            if (self::isContinuation($firstWholeText)) {
                                $startLevel = self::entryLevelBefore(
                                    $handle,
                                    $windowStart + $firstWholePosition,
                                ) ?? Logger::LEVEL_INFO;
                            }
                        }
                    }
                    $matches = self::matchWindow(
                        $buffer,
                        $windowStart,
                        $windowStart > 0,
                        $query,
                        $startLevel,
                        $errorStream,
                    );
                    if (count($matches) > $limit || $windowStart === 0) {
                        return self::tailPageFromMatches($matches, $limit);
                    }
                    if ($windowSize === $ceiling) {
                        return self::tailPageAtCeiling($matches, $buffer, $windowStart);
                    }
                }
            });
        } catch (FsException) {
            return LogLinePage::unavailable();
        }
    }

    /**
     * Size of the file a read holds open, asked of the handle rather than the path.
     *
     * A rotation renames the live file while a read is under way; the handle still names the file the
     * read started on, and its size is the one the offsets inside that read are measured against.
     *
     * @param resource $handle Open read handle
     *
     * @return ?int Size in bytes, or null when the descriptor does not answer
     */
    private static function openFileSize($handle): ?int
    {
        $stat = fstat($handle);

        return $stat === false ? null : $stat['size'];
    }

    /**
     * Find the level of the last entry head before a read cut, looking back one chunk at most.
     *
     * @param resource $handle Open read handle whose position is restored before returning
     * @param int $offset Byte offset of the continuation the caller is about to classify
     *
     * @return ?string Level of the last entry head in reach, or null when the chunk carries none
     */
    private static function entryLevelBefore($handle, int $offset): ?string
    {
        $returnPosition = ftell($handle);
        if ($returnPosition === false) {
            return null;
        }

        try {
            $start = max(0, $offset - self::CHUNK_SIZE);
            fseek($handle, $start);
            $buffer = fread($handle, $offset - $start);
            if ($buffer === false) {
                return null;
            }

            $length = strlen($buffer);
            $position = 0;
            if ($start > 0) {
                $firstNewline = strpos($buffer, "\n");
                $position = $firstNewline === false ? $length : $firstNewline + 1;
            }

            $currentLevel = Logger::LEVEL_INFO;
            $entryLevel = null;
            while ($position < $length) {
                $newline = strpos($buffer, "\n", $position);
                $lineEnd = $newline === false ? $length : $newline + 1;
                $text = rtrim(substr($buffer, $position, $lineEnd - $position), "\r\n");
                [$currentLevel, $isContinuation] = self::classify($text, $currentLevel, false);
                if (!$isContinuation) {
                    $entryLevel = $currentLevel;
                }
                $position = $lineEnd;
            }

            return $entryLevel;
        } finally {
            fseek($handle, $returnPosition);
        }
    }

    /**
     * Classify and filter every complete line in a backward window, tracking level forward.
     *
     * @param string $buffer Raw bytes of the window `[$windowStart, $end)`
     * @param int $windowStart Absolute byte offset the buffer begins at
     * @param bool $dropPartialHead Whether to discard the first line (a fragment when the window does not start at BOF)
     * @param LogReadQuery $query Query providing the filters
     * @param string $startLevel Running level before the first whole line in the window
     * @param bool $errorStream Whether every line of the file carries the ERROR level
     *
     * @return list<array{offset: int, line: LogLine}> Matched lines with their absolute start offsets, in file order
     */
    private static function matchWindow(
        string $buffer,
        int $windowStart,
        bool $dropPartialHead,
        LogReadQuery $query,
        string $startLevel,
        bool $errorStream,
    ): array {
        $matches = [];
        $currentLevel = $startLevel;
        $length = strlen($buffer);
        $position = 0;
        $first = true;
        while ($position < $length) {
            $newline = strpos($buffer, "\n", $position);
            $lineEnd = $newline === false ? $length : $newline + 1;
            $text = rtrim(substr($buffer, $position, $lineEnd - $position), "\r\n");
            $offset = $windowStart + $position;
            $position = $lineEnd;

            if ($first) {
                $first = false;
                if ($dropPartialHead) {
                    continue;
                }
            }

            [$currentLevel, $isContinuation] = self::classify($text, $currentLevel, $errorStream);
            if (self::passesFilter($text, $currentLevel, $query)) {
                $matches[] = ['offset' => $offset, 'line' => new LogLine($text, $currentLevel, $isContinuation)];
            }
        }

        return $matches;
    }

    /**
     * Take the last `$limit` matches as a tail page, deriving the backward cursor from the earliest kept line.
     *
     * @param list<array{offset: int, line: LogLine}> $matches Matched lines with offsets, in file order
     * @param int $limit Positive page size
     *
     * @return LogLinePage Last `$limit` lines in file order; cursor is the earliest kept line's offset when an older MATCH remains
     */
    private static function tailPageFromMatches(array $matches, int $limit): LogLinePage
    {
        $kept = count($matches) > $limit ? array_slice($matches, -$limit) : $matches;
        if ($kept === []) {
            return new LogLinePage(true, [], null, false);
        }

        $earliestOffset = $kept[0]['offset'];
        $lines = array_map(static fn (array $match): LogLine => $match['line'], $kept);
        $hasMore = count($matches) > $limit;

        return new LogLinePage(true, $lines, $hasMore ? $earliestOffset : null, $hasMore);
    }

    /**
     * Take every match of a window its ceiling stopped, handing the window's own boundary back as the cursor.
     *
     * A window stopped by {@see LogReadQuery::$maxWindowBytes} did not look past its left edge, so it cannot say
     * whether an older match remains; the honest answer is "there may be — read on from here" (HIL-868). The
     * boundary is the start of the first line the window read whole: {@see matchWindow()} dropped the fragment
     * in front of it, and a cursor at the raw edge would hand the next page that line cut in two. A window
     * holding no whole line at all — one line longer than the ceiling — gives its raw edge instead, so a caller
     * paging on still moves back rather than being handed the page it already has.
     *
     * @param list<array{offset: int, line: LogLine}> $matches Matched lines with offsets, in file order, no more than the page holds
     * @param string $buffer Raw bytes of the window
     * @param int $windowStart Absolute byte offset the buffer begins at, above zero
     *
     * @return LogLinePage Every match in file order, flagged as possibly preceded by older ones before the boundary
     */
    private static function tailPageAtCeiling(array $matches, string $buffer, int $windowStart): LogLinePage
    {
        $firstNewline = strpos($buffer, "\n");
        $firstWholeLine = $firstNewline === false ? null : $windowStart + $firstNewline + 1;
        $boundary = $firstWholeLine === null || $firstWholeLine === $windowStart + strlen($buffer) ? $windowStart : $firstWholeLine;
        $lines = array_map(static fn (array $match): LogLine => $match['line'], $matches);

        return new LogLinePage(true, $lines, $boundary, true);
    }

    /**
     * Walk the whole stamped lines of a backward window, yielding where each begins and when it was written.
     *
     * The first line is skipped when the window does not start at the beginning of the file: it is a fragment,
     * and its stamp — if the cut happened to leave one — would belong to a line this window never saw whole.
     *
     * @param string $buffer Raw bytes of the window
     * @param int $windowStart Absolute byte offset the buffer begins at
     *
     * @return Generator<int, int> Absolute line offset → unix milliseconds of its stamp, in file order
     */
    private static function stampedLines(string $buffer, int $windowStart): Generator
    {
        $length = strlen($buffer);
        $position = 0;
        if ($windowStart > 0) {
            $firstNewline = strpos($buffer, "\n");
            $position = $firstNewline === false ? $length : $firstNewline + 1;
        }

        while ($position < $length) {
            $newline = strpos($buffer, "\n", $position);
            $lineEnd = $newline === false ? $length : $newline + 1;
            $stamp = self::stampMilliseconds(substr($buffer, $position, $lineEnd - $position));
            if ($stamp !== null) {
                yield $windowStart + $position => $stamp;
            }
            $position = $lineEnd;
        }
    }

    /**
     * Classify one line's level and continuation flag, advancing the running level.
     *
     * @param string $text Line text without the trailing newline
     * @param string $currentLevel Running level inherited from the preceding line
     * @param bool $errorStream Whether every line of the file carries the ERROR level
     *
     * @return array{0: string, 1: bool} New running level and whether the line is a continuation
     */
    private static function classify(string $text, string $currentLevel, bool $errorStream): array
    {
        if (str_starts_with($text, Logger::AGENT_LOG_MARKER)) {
            return [$errorStream ? Logger::LEVEL_ERROR : self::detectAgentLevel($text, $currentLevel), false];
        }
        if (preg_match(self::TIMESTAMP_PREFIX_PATTERN, $text, $match) === 1) {
            return [
                $errorStream ? Logger::LEVEL_ERROR : self::detectEntryLevel(substr($text, strlen($match[0]))),
                false,
            ];
        }

        return [$errorStream ? Logger::LEVEL_ERROR : $currentLevel, true];
    }

    /**
     * @param string $text Line text without the trailing newline
     *
     * @return bool Whether the line carries no entry head of its own
     */
    private static function isContinuation(string $text): bool
    {
        return !str_starts_with($text, Logger::AGENT_LOG_MARKER)
            && preg_match(self::TIMESTAMP_PREFIX_PATTERN, $text) !== 1;
    }

    /**
     * Detect the level of a timestamped entry from its prefix, defaulting to INFO.
     *
     * @param string $afterTimestamp Text following the `[timestamp] ` prefix
     *
     * @return string One of the {@see Logger} `LEVEL_*` constants
     */
    private static function detectEntryLevel(string $afterTimestamp): string
    {
        foreach (self::LEVEL_PREFIXES as $prefix => $level) {
            if (str_starts_with($afterTimestamp, $prefix)) {
                return $level;
            }
        }

        return Logger::LEVEL_INFO;
    }

    /**
     * Detect the level from the `agentId|level|message` agent-pipe format.
     *
     * @param string $text Line beginning with {@see Logger::AGENT_LOG_MARKER}
     * @param string $currentLevel Running level to fall back on when the field is missing or unrecognized
     *
     * @return string One of the {@see Logger} `LEVEL_*` constants
     */
    private static function detectAgentLevel(string $text, string $currentLevel): string
    {
        $fields = explode(
            Logger::AGENT_LOG_FIELD_SEPARATOR,
            substr($text, strlen(Logger::AGENT_LOG_MARKER)),
            Logger::AGENT_LOG_FIELDS_COUNT,
        );
        if (count($fields) < Logger::AGENT_LOG_FIELDS_COUNT) {
            return $currentLevel;
        }

        return self::isKnownLevel($fields[1]) ? $fields[1] : $currentLevel;
    }

    /**
     * Whether a line passes the query's level and substring filters.
     *
     * @param string $text Line text
     * @param string $level Detected level of the line
     * @param LogReadQuery $query Query providing the optional filters
     *
     * @return bool True when the line matches both active filters
     */
    private static function passesFilter(string $text, string $level, LogReadQuery $query): bool
    {
        if ($query->levelFilter !== null && $level !== $query->levelFilter) {
            return false;
        }
        if ($query->substring !== null && $query->substring !== '' && !str_contains($text, $query->substring)) {
            return false;
        }

        return true;
    }

    /**
     * Whether a value is one of the recognized {@see Logger} `LEVEL_*` constants.
     *
     * Public because a caller asking for a filtered read must be able to refuse a level this
     * reader would never match, and the answer is the reader's own: a second list elsewhere
     * would drift into rejecting a level the filter still recognizes.
     *
     * @param string $level Candidate level token
     *
     * @return bool True when the token is a known level
     */
    public static function isKnownLevel(string $level): bool
    {
        return in_array($level, [Logger::LEVEL_INFO, Logger::LEVEL_ERROR, Logger::LEVEL_WARNING, Logger::LEVEL_DEBUG], true);
    }
}
