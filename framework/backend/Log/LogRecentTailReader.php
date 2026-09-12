<?php

declare(strict_types=1);

namespace Hilos\Log;

use Hilos\Constants\ErrorConstants;
use Hilos\Utils\Logger;

/**
 * Stateless read service turning the tail of one live stream into entries of the recent-failures panel (HIL-867).
 *
 * Sits on top of {@see LogLineReader}: that reads the last lines of a file, this decides which of
 * them are entries and what each one says. Both of the panel's tabs are read here (HIL-868): errors
 * off the tail of an error stream as it stands, warnings off the tail of an ordinary stream under a
 * level filter and a window ceiling — a warning has no file of its own, and a stream without one
 * would otherwise be read whole. The shape it expects is the one {@see Logger} writes —
 * `[YYYY-MM-DD HH:MM:SS.mmm] text{"context":…}` — with the context, stack trace and all, encoded as
 * JSON inside the very same line. That is why an entry here is one line and never a run of them,
 * and why the line has to be taken apart rather than shown whole: one such line weighed four
 * kilobytes on a live stand. The level prefix a line of an ordinary stream opens with comes off
 * too ({@see LogLineReader::textAfterLevel()}): the tab an entry sits in already names its level,
 * and the row draws it as a tag of its own (HIL-868).
 *
 * A line without a timestamp is dropped rather than shown with a made-up time. Error streams hold
 * no continuation lines by construction, while an ordinary stream may, and anything may append to
 * either past the Logger — and an entry that cannot be ordered cannot be cut off by the panel's
 * window either, so silence is the honest answer.
 *
 * When a line was written is {@see LogLineReader::stampMilliseconds()}'s answer, not this reader's.
 * Holds no state; an unreadable or missing file yields an empty list, not a refusal.
 */
final class LogRecentTailReader
{
    /**
     * Start of the JSON context {@see Logger} appends to an entry, separated from the text by a space.
     *
     * Scanned for left to right because the text of a message may contain the same two characters;
     * the first position from which the rest of the line decodes as JSON is the real boundary.
     */
    private const string CONTEXT_OPENING = ' {';

    /** Splits a stack trace rendered by `Throwable::getTraceAsString()` into frames. */
    private const string TRACE_FRAME_SEPARATOR = '/\R/';

    /**
     * @param LogLineReader $lineReader Reader over the log root the streams live in
     * @param int $messageMaxChars Maximum length of {@see LogRecentEntry::$message}; the rest stays in the viewer
     */
    public function __construct(
        private readonly LogLineReader $lineReader,
        private readonly int $messageMaxChars,
    ) {
    }

    /**
     * Read the newest entries of one live stream.
     *
     * @param string $basename Basename of the live stream under the log root
     * @param int $limit Maximum number of lines to take from the end of the file
     * @param ?string $level Keep only lines of this level (a {@see Logger} `LEVEL_*` value), or null to take every line
     * @param ?int $maxWindowBytes Furthest to read back from the end of the file, in bytes, or null to read back as far
     *     as the page needs
     *
     * @return list<LogRecentEntry> Entries newest first; empty when the file is unreadable or holds no entry
     */
    public function readStream(string $basename, int $limit, ?string $level = null, ?int $maxWindowBytes = null): array
    {
        $page = $this->lineReader->read(
            $basename,
            new LogReadQuery(LogReadQuery::ANCHOR_TAIL, limit: $limit, levelFilter: $level, maxWindowBytes: $maxWindowBytes),
        );

        $entries = [];
        foreach ($page->lines as $line) {
            $entry = $this->parse($line->text, $basename);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return array_reverse($entries);
    }

    /**
     * Turn one physical line into an entry.
     *
     * @param string $text Line text as read from the file
     * @param string $basename Basename of the stream the line came from
     *
     * @return ?LogRecentEntry Parsed entry, or null when the line carries no timestamp to order it by
     */
    private function parse(string $text, string $basename): ?LogRecentEntry
    {
        if (preg_match(LogLineReader::TIMESTAMP_PREFIX_PATTERN, $text, $match) !== 1) {
            return null;
        }

        $stamp = LogLineReader::stampMilliseconds($text);
        if ($stamp === null) {
            return null;
        }

        $rest = LogLineReader::textAfterLevel(substr($text, strlen($match[0])));
        $message = $rest;
        $traceFrames = null;
        $offset = 0;
        while (($opening = strpos($rest, self::CONTEXT_OPENING, $offset)) !== false) {
            $context = json_decode(substr($rest, $opening + 1), true);
            if (is_array($context)) {
                $message = rtrim(substr($rest, 0, $opening));
                $traceFrames = self::traceFrames($context);
                break;
            }
            $offset = $opening + 1;
        }

        return new LogRecentEntry($stamp, $basename, mb_substr($message, 0, $this->messageMaxChars), $traceFrames);
    }

    /**
     * Count the frames of the stack trace an entry's context carries.
     *
     * The trace is rendered by `Throwable::getTraceAsString()` and therefore arrives as one string
     * of newline-separated frames; a context built by hand may carry a list instead, and both
     * answer the same question. Anything else is treated as no trace at all — the badge this feeds
     * says "there is a stack to look at", and a number pulled out of an unknown shape would not.
     *
     * @param array<mixed> $context Decoded JSON context of the entry
     *
     * @return ?int Number of frames, or null when the entry carries no usable trace
     */
    private static function traceFrames(array $context): ?int
    {
        if (!isset($context[ErrorConstants::CONTEXT_KEY_TRACE])) {
            return null;
        }

        $trace = $context[ErrorConstants::CONTEXT_KEY_TRACE];
        if (is_array($trace)) {
            return count($trace) === 0 ? null : count($trace);
        }
        if (!is_string($trace)) {
            return null;
        }

        $frames = preg_split(self::TRACE_FRAME_SEPARATOR, trim($trace));
        if ($frames === false) {
            return null;
        }

        $frames = array_filter($frames, static fn (string $frame): bool => trim($frame) !== '');

        return count($frames) === 0 ? null : count($frames);
    }
}
