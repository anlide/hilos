<?php

declare(strict_types=1);

namespace Hilos\Log;

/**
 * Immutable read model of one line kept for the "recent failures" panel (HIL-867, HIL-868).
 *
 * The same shape serves both of the panel's tabs, errors and warnings: what an entry is and what it
 * says does not depend on its level, only which lines are picked does. Produced by
 * {@see LogRecentTailReader} from the tail of one live stream and carried through
 * {@see NodeLogIndex} to the logs overview screen. Deliberately not the whole line: {@see $message}
 * is already cut to the node's limit, because one such entry weighs kilobytes — the stack trace
 * rides inside the same line as a JSON context — and the index frame must stay small. The full text
 * lives in the log viewer the panel row links to.
 *
 * {@see $atMs} is unix milliseconds: the stamp is parsed on the node that wrote the file, in that
 * node's own timezone, because only it knows one. Internal read value-object, not a signal payload.
 */
final class LogRecentEntry
{
    /**
     * @param int $atMs Time the line was written, unix milliseconds
     * @param string $stream Basename of the live stream the line was read from (e.g. `worker-monopolistic-5.error.log`)
     * @param string $message Line text without its timestamp, level prefix and context, already cut to the reader's limit
     * @param ?int $traceFrames Number of frames in the entry's stack trace, or null when the entry carries none
     */
    public function __construct(
        public readonly int $atMs,
        public readonly string $stream,
        public readonly string $message,
        public readonly ?int $traceFrames,
    ) {
    }
}
