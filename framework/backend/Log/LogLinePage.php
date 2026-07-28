<?php

declare(strict_types=1);

namespace Hilos\Log;

/**
 * Immutable result of one {@see LogLineReader::read()} call (HIL-384).
 *
 * Carries the matched {@see LogLine} slice plus the cursor to fetch the adjacent page. A missing or
 * unreadable file, or a path rejected by the traversal guard, is part of the result rather than an
 * exception: {@see unavailable()} sets {@see $readable} false and returns no lines, mirroring the
 * unavailable state the store enumeration API ({@see LogStoreSnapshot}) uses. Internal read
 * value-object, not a signal payload.
 */
final class LogLinePage
{
    /**
     * @param bool $readable Whether the requested file could be read
     * @param list<LogLine> $lines Matched lines in file (chronological) order, up to the query limit
     * @param ?int $nextCursor Byte offset to pass back as {@see LogReadQuery::$cursor} for the adjacent page, or null when none remain
     * @param bool $hasMore Whether more matching lines exist beyond this page in the read direction
     */
    public function __construct(
        public readonly bool $readable,
        public readonly array $lines,
        public readonly ?int $nextCursor,
        public readonly bool $hasMore,
    ) {
    }

    /**
     * Empty page for a missing/unreadable file or a path outside the traversal guard.
     *
     * @return self Page with {@see $readable} false, no lines and no cursor
     */
    public static function unavailable(): self
    {
        return new self(false, [], null, false);
    }
}
