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
 * This dictionary gathers the stream extensions, worker and agent prefixes. The monopolistic
 * worker prefix is an expression rather than a string literal so that renaming the worker type
 * automatically updates the reader. Daemon stream names are not gathered here as they come
 * from the environment and are recognized by their exact basename.
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

    public const string STREAM_SUFFIX = '.log';
    public const string WORKER_STREAM_PREFIX = 'worker-';
    public const string AGENT_STREAM_PREFIX = 'agent-';
    public const string WORKER_TYPE_SEPARATOR = '-';
    public const string MONOPOLISTIC_WORKER_STREAM_PREFIX = self::WORKER_STREAM_PREFIX . WorkerConstants::TYPE_MONOPOLISTIC
        . self::WORKER_TYPE_SEPARATOR;
    public const string LIVE_STREAM_GLOB = '*' . self::STREAM_SUFFIX;
}
