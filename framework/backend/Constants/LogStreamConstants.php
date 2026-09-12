<?php

declare(strict_types=1);

namespace Hilos\Constants;

use Hilos\Log\LogStoreReader;
use Hilos\Socket\Server\WorkerServer;

/**
 * Naming of the daemon's live log streams that both a writer and a reader depend on (HIL-867).
 *
 * A stream name is a contract between two sides that never meet: the master names the file it
 * spills a worker's stderr and ERROR level into, and a reader decides from the same name whether
 * what it found is a stream of failures. Declared twice, the two sides drift apart silently —
 * nothing fails, the reader simply stops seeing what the writer writes.
 *
 * Only the names carrying such a cross-side contract live here. The remaining stream names (the
 * worker, monopolistic-worker and agent prefixes, the plain `.log` extension) are still declared
 * on both sides and are not gathered by this class — that is proposal P-273.
 */
final class LogStreamConstants
{
    /**
     * Filename suffix of a live stream carrying failures: worker stderr and every ERROR level.
     *
     * Written by {@see WorkerServer}, recognized by {@see LogStoreReader::isErrorStream()}. The
     * daemon's own error stream is named by `DAEMON_ERROR_LOG_FILE` instead and does not carry
     * this suffix.
     */
    public const string ERROR_STREAM_SUFFIX = '.error.log';
}
