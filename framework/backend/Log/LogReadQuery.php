<?php

declare(strict_types=1);

namespace Hilos\Log;

use Hilos\Utils\Logger;

/**
 * Immutable request describing one page to read from a single log file (HIL-384).
 *
 * Passed to {@see LogLineReader::read()}. {@see $anchor} picks which end to read from — {@see ANCHOR_HEAD}
 * walks forward from the start, {@see ANCHOR_TAIL} walks backward from the end (the live-tail default) —
 * and {@see $cursor} continues a previous page from the {@see LogLinePage::$nextCursor} it returned; null
 * means start at the anchor's natural end. Up to {@see $limit} lines that pass the optional
 * {@see $levelFilter} (a {@see Logger} `LEVEL_*` value) and {@see $substring} filter are returned.
 * {@see $inheritedLevel} carries the running level across a cut, so a live tail resuming mid-entry keeps
 * the stack trace of an ERROR classified as ERROR (HIL-389). Internal read value-object, not a signal
 * payload.
 */
final class LogReadQuery
{
    /** Read forward from the start of the file (or from {@see $cursor}). */
    public const string ANCHOR_HEAD = 'head';

    /** Read backward from the end of the file (or from {@see $cursor}); the live-tail default. */
    public const string ANCHOR_TAIL = 'tail';

    /**
     * @param string $anchor Which end to read from: {@see ANCHOR_HEAD} or {@see ANCHOR_TAIL}
     * @param ?int $cursor Byte offset to continue from (a prior {@see LogLinePage::$nextCursor}), or null to start at the anchor's natural end
     * @param int $limit Maximum number of matched lines to return (clamped to at least 1 by the reader)
     * @param ?string $levelFilter Keep only lines of this level (a {@see Logger} `LEVEL_*` value), or null for any level
     * @param ?string $substring Keep only lines containing this text, or null/empty for no substring filter
     * @param ?string $inheritedLevel Entry level (a {@see Logger} `LEVEL_*` value) inherited from the page before this
     *     one, so a continuation opening this page keeps its entry's level; null starts the scan at the reader's
     *     {@see Logger::LEVEL_INFO} default
     */
    public function __construct(
        public readonly string $anchor,
        public readonly ?int $cursor = null,
        public readonly int $limit = 200,
        public readonly ?string $levelFilter = null,
        public readonly ?string $substring = null,
        public readonly ?string $inheritedLevel = null,
    ) {
    }
}
