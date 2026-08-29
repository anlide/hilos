<?php

declare(strict_types=1);

namespace Hilos\Log;

use Hilos\Constants\EnvConstants;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Utils\Logger;

/**
 * Stateless read primitive over one selected daemon log file (HIL-384).
 *
 * Companion to {@see LogStoreReader}: where that enumerates the store, this reads the lines of a single
 * chosen file with cursor pagination and level/substring filtering, feeding the log viewer (HIL-388)
 * and live-tail (HIL-389) pages from the worker, off the master loop. Like the store reader it holds no
 * state and does no DI — it is bound to the log root and each {@see read()} does a fresh filesystem
 * read (it reads the filesystem, not a `DbCollection`, so the no-repository-service rule does not apply).
 *
 * Two independent scans share one line classifier: {@see LogReadQuery::ANCHOR_HEAD} walks forward from
 * the cursor a line at a time; {@see LogReadQuery::ANCHOR_TAIL} grows a byte window backward from the end
 * in {@see CHUNK_SIZE} steps until it holds the requested number of matches, so large files are never
 * loaded whole for the common tail case. Level detection is per line: a recognized prefix
 * (`[ERROR]`/`ERROR:` and the like, or the `agentId|level|message` agent-pipe format under
 * {@see Logger::AGENT_LOG_MARKER}) updates a running level, a line without one is a continuation that
 * inherits it — so an `ERROR` filter also catches the entry's stack trace. The running level resets to
 * {@see Logger::LEVEL_INFO} at each page boundary, so a continuation at the very start of a page defaults
 * to INFO rather than inheriting across the cut.
 *
 * A missing/unreadable file, or a path escaping the log root via {@see realpath()} validation, yields
 * {@see LogLinePage::unavailable()} rather than a fatal.
 */
final class LogLineReader
{
    /** Window growth step (bytes) for the backward tail scan. */
    private const int CHUNK_SIZE = 65536;

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

    /** Matches the `[YYYY-MM-DD HH:MM:SS.mmm] ` prefix a fresh log entry starts with. */
    private const string TIMESTAMP_PREFIX_PATTERN = '/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}\] /';

    /**
     * @param ?string $logDirectory Log root holding the live `*.log` files and the archive subtree, or null when it could not be resolved
     */
    public function __construct(private readonly ?string $logDirectory)
    {
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
        try {
            return new self(dirname(Hilos::$env[EnvConstants::DAEMON_LOG_FILE]));
        } catch (EnvException) {
            return new self(null);
        }
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

        return $query->anchor === LogReadQuery::ANCHOR_TAIL
            ? $this->readTail($path, $query, $limit)
            : $this->readHead($path, $query, $limit);
    }

    /**
     * Validate that a relative path resolves to a readable file inside the log root.
     *
     * The traversal guard is `realpath()`-based: both the root and the candidate are canonicalized and
     * the candidate must sit under the root, defeating `..` escapes and symlinks pointing outside.
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
        if (!is_file($real) || !is_readable($real)) {
            return null;
        }

        return $real;
    }

    /**
     * Scan forward from the cursor, collecting up to `$limit` matched lines.
     *
     * @param string $path Canonical file path
     * @param LogReadQuery $query Query providing the cursor and filters
     * @param int $limit Positive page size
     *
     * @return LogLinePage Matched lines in file order plus the next forward cursor
     */
    private function readHead(string $path, LogReadQuery $query, int $limit): LogLinePage
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return LogLinePage::unavailable();
        }

        $fileSize = filesize($path);
        $start = max(0, $query->cursor ?? 0);
        if ($fileSize === false || $start >= $fileSize) {
            fclose($handle);

            return new LogLinePage(true, [], null, false);
        }
        fseek($handle, $start);

        $lines = [];
        $currentLevel = Logger::LEVEL_INFO;
        $nextCursor = null;
        $hasMore = false;
        while (($raw = fgets($handle)) !== false) {
            $text = rtrim($raw, "\r\n");
            [$currentLevel, $isContinuation] = self::classify($text, $currentLevel);
            if (self::passesFilter($text, $currentLevel, $query)) {
                $lines[] = new LogLine($text, $currentLevel, $isContinuation);
                if (count($lines) === $limit) {
                    $position = ftell($handle);
                    $hasMore = $position !== false && $position < $fileSize;
                    $nextCursor = $hasMore ? $position : null;
                    break;
                }
            }
        }
        fclose($handle);

        return new LogLinePage(true, $lines, $nextCursor, $hasMore);
    }

    /**
     * Grow a window backward from the cursor until it holds `$limit` matches (or reaches the start).
     *
     * @param string $path Canonical file path
     * @param LogReadQuery $query Query providing the cursor and filters
     * @param int $limit Positive page size
     *
     * @return LogLinePage The last `$limit` matched lines in file order plus the next backward cursor
     */
    private function readTail(string $path, LogReadQuery $query, int $limit): LogLinePage
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return LogLinePage::unavailable();
        }

        $fileSize = filesize($path);
        if ($fileSize === false) {
            fclose($handle);

            return LogLinePage::unavailable();
        }

        $end = $query->cursor ?? $fileSize;
        $end = max(0, min($end, $fileSize));
        if ($end === 0) {
            fclose($handle);

            return new LogLinePage(true, [], null, false);
        }

        $windowSize = 0;
        while (true) {
            $windowSize = min($end, $windowSize + self::CHUNK_SIZE);
            $windowStart = $end - $windowSize;
            fseek($handle, $windowStart);
            $buffer = fread($handle, $windowSize);
            if ($buffer === false) {
                fclose($handle);

                return LogLinePage::unavailable();
            }

            $matches = self::matchWindow($buffer, $windowStart, $windowStart > 0, $query);
            if (count($matches) >= $limit || $windowStart === 0) {
                fclose($handle);

                return self::tailPageFromMatches($matches, $limit);
            }
        }
    }

    /**
     * Classify and filter every complete line in a backward window, tracking level forward.
     *
     * @param string $buffer Raw bytes of the window `[$windowStart, $end)`
     * @param int $windowStart Absolute byte offset the buffer begins at
     * @param bool $dropPartialHead Whether to discard the first line (a fragment when the window does not start at BOF)
     * @param LogReadQuery $query Query providing the filters
     *
     * @return list<array{offset: int, line: LogLine}> Matched lines with their absolute start offsets, in file order
     */
    private static function matchWindow(string $buffer, int $windowStart, bool $dropPartialHead, LogReadQuery $query): array
    {
        $matches = [];
        $currentLevel = Logger::LEVEL_INFO;
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

            [$currentLevel, $isContinuation] = self::classify($text, $currentLevel);
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
     * @return LogLinePage Last `$limit` lines in file order; cursor is the earliest kept line's offset when older content remains
     */
    private static function tailPageFromMatches(array $matches, int $limit): LogLinePage
    {
        $kept = count($matches) > $limit ? array_slice($matches, -$limit) : $matches;
        if ($kept === []) {
            return new LogLinePage(true, [], null, false);
        }

        $earliestOffset = $kept[0]['offset'];
        $lines = array_map(static fn (array $match): LogLine => $match['line'], $kept);
        $hasMore = $earliestOffset > 0;

        return new LogLinePage(true, $lines, $hasMore ? $earliestOffset : null, $hasMore);
    }

    /**
     * Classify one line's level and continuation flag, advancing the running level.
     *
     * @param string $text Line text without the trailing newline
     * @param string $currentLevel Running level inherited from the preceding line
     *
     * @return array{0: string, 1: bool} New running level and whether the line is a continuation
     */
    private static function classify(string $text, string $currentLevel): array
    {
        if (str_starts_with($text, Logger::AGENT_LOG_MARKER)) {
            return [self::detectAgentLevel($text, $currentLevel), false];
        }
        if (preg_match(self::TIMESTAMP_PREFIX_PATTERN, $text, $match) === 1) {
            return [self::detectEntryLevel(substr($text, strlen($match[0]))), false];
        }

        return [$currentLevel, true];
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
