<?php

declare(strict_types=1);

namespace Hilos\Log;

use Hilos\Utils\Logger;

/**
 * Immutable read model of one physical line of a daemon log file (HIL-384).
 *
 * Produced by {@see LogLineReader} while scanning a single log file. {@see $detectedLevel} is a
 * best-effort classification (one of the {@see Logger} `LEVEL_*` constants): a line carrying a
 * recognized prefix sets the level, while a line without one is a {@see $isContinuation} (a wrapped
 * message body or exception stack trace) that inherits the level of the entry it belongs to. This is
 * what lets a level filter for `ERROR` also carry that entry's stack-trace lines. Internal read
 * value-object, not a signal payload.
 */
final class LogLine
{
    /**
     * @param string $text Line text with the trailing newline stripped
     * @param string $detectedLevel Best-effort level, one of the {@see Logger} `LEVEL_*` constants
     * @param bool $isContinuation Whether the line is a continuation inheriting the previous entry's level
     */
    public function __construct(
        public readonly string $text,
        public readonly string $detectedLevel,
        public readonly bool $isContinuation,
    ) {
    }
}
