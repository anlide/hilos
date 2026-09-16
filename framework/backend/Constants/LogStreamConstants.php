<?php

declare(strict_types=1);

namespace Hilos\Constants;

use Hilos\Log\AgentLogStream;
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
     * Written by {@see WorkerServer} for a worker and by {@see AgentLogStream} for an agent,
     * recognized by {@see LogStoreReader::isErrorStream()}. The daemon's own error stream is named
     * by `DAEMON_ERROR_LOG_FILE` instead and does not carry this suffix.
     */
    public const string ERROR_STREAM_SUFFIX = '.error.log';

    /**
     * Filename suffix every live stream ends with, the error twin included.
     *
     * Live streams are found by it (`LIVE_STREAM_GLOB`), and the archive pruner counts a file in a
     * batch as one rotation moved there by it.
     */
    public const string STREAM_SUFFIX = '.log';

    /**
     * Filename prefix of a worker's streams: this prefix, the worker type, `WORKER_TYPE_SEPARATOR`,
     * the worker index and a suffix.
     *
     * Written by {@see WorkerServer}, classified by {@see LogStoreReader}.
     */
    public const string WORKER_STREAM_PREFIX = 'worker-';

    /**
     * Filename prefix of an agent's streams, followed by the sanitized agent id and a suffix.
     *
     * Written by {@see AgentLogStream}, classified by {@see LogStoreReader}.
     */
    public const string AGENT_STREAM_PREFIX = 'agent-';

    /**
     * Joins the worker type to the worker index in a worker stream name.
     *
     * The same value closes `MONOPOLISTIC_WORKER_STREAM_PREFIX`, so the name the writer composes and
     * the prefix the reader tests are one quantity, not two strings that happen to agree.
     */
    public const string WORKER_TYPE_SEPARATOR = '-';

    /**
     * Filename prefix of a monopolistic worker's streams.
     *
     * Nobody writes this string out: the writer composes it from the worker type, so it is built
     * here from the same parts and a renamed {@see WorkerConstants::TYPE_MONOPOLISTIC} moves the
     * reader along. Every name it matches also starts with `WORKER_STREAM_PREFIX`, which is why
     * the reader tests this prefix first.
     */
    public const string MONOPOLISTIC_WORKER_STREAM_PREFIX = self::WORKER_STREAM_PREFIX . WorkerConstants::TYPE_MONOPOLISTIC
        . self::WORKER_TYPE_SEPARATOR;

    /**
     * Glob of the live stream files lying directly in a log root; the staging and archive subtrees
     * are not matched.
     *
     * Read by {@see LogStoreReader}, by rotation and by the reset of the test log root.
     */
    public const string LIVE_STREAM_GLOB = '*' . self::STREAM_SUFFIX;
}
