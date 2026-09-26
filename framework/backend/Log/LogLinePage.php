<?php

declare(strict_types=1);

namespace Hilos\Log;

use Hilos\Utils\Logger;

/**
 * Immutable result of one {@see LogLineReader::read()} call (HIL-384).
 *
 * Carries the matched {@see LogLine} slice plus the cursor to fetch the adjacent page. A missing or
 * unreadable file, or a path rejected by the traversal guard, is part of the result rather than an
 * exception: {@see unavailable()} sets {@see $readable} false and returns no lines, mirroring the
 * unavailable state the store enumeration API ({@see LogStoreSnapshot}) uses. Internal read
 * value-object, not a signal payload.
 *
 * {@see $nextCursor} answers "is there another page" and is null at the end of the file, so a live tail
 * cannot follow it; {@see $endCursor} and {@see $endLevel} answer "where did this read stop, and what
 * level does the next line inherit". A readable forward read always fills the cursor; it fills the
 * level after consuming a complete line or when the query supplied one (HIL-389, HIL-1025).
 */
final class LogLinePage
{
    /**
     * @param bool $readable Whether the requested file could be read
     * @param list<LogLine> $lines Matched lines in file (chronological) order, up to the query limit
     * @param ?int $nextCursor Byte offset to pass back as {@see LogReadQuery::$cursor} for the adjacent page, or null when none remain
     * @param bool $hasMore Whether an older matching line remains before this page, for a backward tail scan: it
     *     looks one match past the page to answer, so the flag is exact — unless {@see LogReadQuery::$maxWindowBytes}
     *     stopped the scan first, which cannot know and answers true with the window's boundary as the cursor. A
     *     forward read carries a weaker promise — it stopped on the limit with bytes still unread, which under a
     *     filter need not hold another match
     * @param ?int $endCursor Byte offset just past the last complete line this read consumed, or null when the
     *     read direction does not track one (a backward tail scan, or an unavailable file)
     * @param ?string $endLevel Running entry level (a {@see Logger} `LEVEL_*` value) at {@see $endCursor}, to be
     *     passed back as {@see LogReadQuery::$inheritedLevel}; null when {@see $endCursor} is null or a forward read
     *     received no inherited level and consumed no complete line
     */
    public function __construct(
        public readonly bool $readable,
        public readonly array $lines,
        public readonly ?int $nextCursor,
        public readonly bool $hasMore,
        public readonly ?int $endCursor = null,
        public readonly ?string $endLevel = null,
    ) {
    }

    /**
     * Empty page for a missing/unreadable file or a path outside the traversal guard.
     *
     * @return self Page with {@see $readable} false, no lines and neither cursor
     */
    public static function unavailable(): self
    {
        return new self(false, [], null, false, null, null);
    }
}
