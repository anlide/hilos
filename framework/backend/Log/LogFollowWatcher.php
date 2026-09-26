<?php

declare(strict_types=1);

namespace Hilos\Log;

/**
 * One viewer following one live log file, as {@see LogStoreAgent} holds it in memory (HIL-389).
 *
 * Two halves. What the viewer asked for never changes - the connection, the follow id, the stream
 * and the filters - and a change of any of them is a new follow replacing this one, because the
 * viewer has one screen. Where the reading has got to does change, every tick, and that is the
 * whole reason this is the one mutable class of the leaf: a read position is a state, not a value,
 * and an immutable copy per viewer per second would be garbage minted for the shape of it.
 *
 * {@see $inheritedLevel} is the second half of the position. A known value carries an entry level
 * across a tick boundary; null asks the reader to recover it from the stream or the entry head at
 * most one read step behind the position (HIL-384, HIL-1025).
 */
final class LogFollowWatcher
{
    /**
     * @param string $acceptKey Accept key of the connection receiving the frames
     * @param string $requestId Request id of the start, stamped on every frame as the follow id
     * @param string $stream File name of the live stream being followed
     * @param ?string $level Level filter, or null for any level
     * @param ?string $substring Substring filter, or null for no substring filter
     * @param int $offset Byte offset reading has reached
     * @param ?string $inheritedLevel Entry level a line at {@see $offset} inherits, or null for the reader to detect it
     */
    public function __construct(
        public readonly string $acceptKey,
        public readonly string $requestId,
        public readonly string $stream,
        public readonly ?string $level,
        public readonly ?string $substring,
        private int $offset,
        private ?string $inheritedLevel = null,
    ) {
    }

    /**
     * @return int Byte offset reading has reached
     */
    public function offset(): int
    {
        return $this->offset;
    }

    /**
     * @return ?string Entry level a line at {@see offset()} inherits, or null for the reader to detect it
     */
    public function inheritedLevel(): ?string
    {
        return $this->inheritedLevel;
    }

    /**
     * Moves the position to where the read stopped, and remembers the level it stopped on.
     *
     * A page that reached no complete line reports no level, and the position stays where it was:
     * the writer is mid-line and the rest of it is coming.
     *
     * @param ?int $endCursor Byte offset the read stopped at, or null when it reached no complete line
     * @param ?string $endLevel Entry level at that offset, or null when the read consumed no complete line
     */
    public function advanceTo(?int $endCursor, ?string $endLevel): void
    {
        if ($endCursor === null || $endLevel === null) {
            return;
        }

        $this->offset = $endCursor;
        $this->inheritedLevel = $endLevel;
    }

    /**
     * Moves the position without reading what is in between, and forgets the inherited level.
     *
     * Both jumps use it: back to the start of a file that rotation replaced, and forward to the
     * end of one the viewer fell too far behind. Neither carries a known entry level; the next read
     * detects one from the stream or at most one read step behind its new position.
     *
     * @param int $offset Byte offset to continue from
     */
    public function jumpTo(int $offset): void
    {
        $this->offset = $offset;
        $this->inheritedLevel = null;
    }
}
