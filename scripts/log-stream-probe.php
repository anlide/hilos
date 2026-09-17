<?php

declare(strict_types=1);

/**
 * A probe the log-stream check installs INSIDE the daemon container, never into any bootstrap
 * (HIL-1018).
 *
 * Rows 6, 14, 15 and 19 of the HIL-872 map need a real PHP warning and a real PHP fatal inside
 * the master. The only lever that puts no self-destruct into product code is this file: copied
 * into the container's own layer by `scripts/check-log-streams.php`, named as `auto_prepend_file`
 * in a `conf.d` ini the same scenario writes, and removed with it at any outcome. It ships as a
 * file of the check and is wired nowhere; the repository's own tests never load it.
 *
 * It returns at once unless the script PHP is about to run is `daemon.php`, so the cli container,
 * the workers and the watchdog run untouched under the same ini. What it arms is two signals:
 *
 *   SIGUSR2  a real E_WARNING, by opening a path that does not exist. The master's error handler
 *            turns it into an ErrorException, files "WARNING in <file>:<line> - ..." and the node
 *            leaves — which is the behavior the map records and the check asserts.
 *   SIGUSR1  a real fatal, by growing a hoard past memory_limit. The shutdown handler files
 *            "FATAL SHUTDOWN: ..." and PHP prints its own text past the Logger, which is the
 *            whole point: that text is what the raw pair exists to catch.
 *
 * `pcntl_async_signals` so the handler runs inside whatever the loop is doing, including its
 * sleep; the daemon installs its own handlers for other signals and leaves these two alone.
 */

/** What the master's bootstrap script is called; the gate that keeps every other process out. */
const LOG_STREAM_PROBE_DAEMON_SCRIPT = 'daemon.php';

/** A path that does not exist, opened to raise the warning; its name is what the check's pattern matches. */
const LOG_STREAM_PROBE_WARNING_PATH = '/nonexistent/hilos-log-stream-probe-warning';

/** A memory limit to run the hoard against when the image has none, so the fatal arrives rather than the OOM killer. */
const LOG_STREAM_PROBE_MEMORY_LIMIT = '256M';

/** What PHP answers for `memory_limit` when there is no limit at all. */
const LOG_STREAM_PROBE_UNLIMITED = -1;

/** How much the hoard grows by at a time, in bytes; a megabyte reaches the limit in a blink and past it in one step. */
const LOG_STREAM_PROBE_HOARD_STEP_BYTES = 1 << 20;

$script = $_SERVER['argv'][0] ?? null;
if (PHP_SAPI !== 'cli' || !is_string($script) || basename($script) !== LOG_STREAM_PROBE_DAEMON_SCRIPT) {
    return;
}

pcntl_async_signals(true);

pcntl_signal(SIGUSR2, static function (): void {
    // A real E_WARNING past nothing: the whole reason this file exists.
    fopen(LOG_STREAM_PROBE_WARNING_PATH, 'r');
});

pcntl_signal(SIGUSR1, static function (): void {
    if ((int)ini_get('memory_limit') === LOG_STREAM_PROBE_UNLIMITED) {
        ini_set('memory_limit', LOG_STREAM_PROBE_MEMORY_LIMIT);
    }
    $hoard = [];
    while (true) {
        $hoard[] = str_repeat('x', LOG_STREAM_PROBE_HOARD_STEP_BYTES);
    }
});
