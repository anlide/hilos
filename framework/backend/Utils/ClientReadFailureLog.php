<?php

declare(strict_types=1);

namespace Hilos\Utils;

use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Exception\MalformedInput;
use Hilos\Socket\Server\AbstractServer;
use Hilos\Socket\SocketException;
use Throwable;

/**
 * Writes the journal line for a client read that failed, for both readers of a client.
 *
 * One client is read from two places in the master: the tick of its server
 * ({@see AbstractServer::onTick()}) and the event loop's read handler
 * ({@see DaemonManager::onClientRead()}). Which of them gets a given failure is decided
 * by how the connection happens to be registered, not by what went wrong, so the two
 * had to agree on what a failure looks like in the journal — and while the wording was
 * already shared, the level was not: the tick told routine refusals from a broken node
 * and the event loop wrote everything as an error. The same bad line therefore read as a
 * warning or as an error depending on which path picked it up. Keeping that agreement in
 * two copies is what let them part company, so the line, the level and the limit live
 * here and the reader names itself with a parameter.
 *
 * The level asks the failure what it is: a transport that broke ({@see SocketException})
 * or input that could not be parsed ({@see MalformedInput}) is the daily work of an open
 * port and reaches the journal as a warning, and everything else is the node's own
 * trouble and stays an error.
 *
 * A stream of refusals must not bury the journal, so the warning branch is rate limited
 * per exception class and server: the first {@see self::BURST_LINES} of a window are
 * written in full, the rest are counted, and the closing summary says how many were held
 * back. The error branch is never limited — the node breaking is exactly what the reader
 * came for. The limit lives here rather than in {@see Logger} because a limiter there
 * would quietly eat lines other subsystems count events by.
 *
 * The counters are static fields of the master process, which is where both readers run;
 * no runtime collection and no lock is involved.
 */
class ClientReadFailureLog
{
    /** Number of failures per key written in full before a window starts counting instead */
    public const int BURST_LINES = 3;

    /** Length of the window a key's failures are counted over, in seconds */
    public const float WINDOW_SECONDS = 60.0;

    /** Reader name for the server tick */
    public const string READER_TICK = 'client tick';

    /** Reader name for the event loop's read handler */
    public const string READER_EVENT_LOOP = 'client read handler';

    /** Journal line for one failed read: reader, server, exception class, file, line, message */
    private const string ENTRY_FORMAT = 'Error in %s for %s: %s in %s:%d - %s';

    /** Journal line closing a window: held-back count, exception class, server, window length */
    private const string SUMMARY_FORMAT = 'Suppressed %d more %s failures for %s in the last %d seconds';

    /**
     * Open windows by key, each holding when it opened and what it has seen.
     *
     * @var array<string, array{openedAt: float, written: int, held: int, failureClass: string, serverName: string}>
     */
    private static array $windows = [];

    /**
     * Writes the journal line for a client read that failed.
     *
     * @param string $serverName Name of the server the client belongs to
     * @param string $reader Reader that caught the failure, one of the READER_* constants
     * @param Throwable $failure Failure the read ended with
     * @param float $now Current time, as the caller reads it
     */
    public static function write(string $serverName, string $reader, Throwable $failure, float $now): void
    {
        $entry = sprintf(
            self::ENTRY_FORMAT,
            $reader,
            $serverName,
            get_class($failure),
            basename($failure->getFile()),
            $failure->getLine(),
            $failure->getMessage()
        );

        if (!$failure instanceof SocketException && !$failure instanceof MalformedInput) {
            Logger::error($entry);
            return;
        }

        if (self::admits($serverName, $failure, $now)) {
            Logger::warning($entry);
        }
    }

    /**
     * Writes the summary of every window whose length has run out, and forgets it.
     *
     * Called from the daemon loop rather than from the next failure of the same kind:
     * a stream that stopped would otherwise leave its tail uncounted until something
     * of that exact kind failed again, which for a peer that went away for good is
     * never.
     *
     * @param float $now Current time, as the caller reads it
     */
    public static function flushClosedWindows(float $now): void
    {
        foreach (self::$windows as $key => $window) {
            if ($now - $window['openedAt'] < self::WINDOW_SECONDS) {
                continue;
            }

            self::summarize($window);
            unset(self::$windows[$key]);
        }
    }

    /**
     * Forgets every open window without writing its summary.
     *
     * For a test that needs the next case to start counting from nothing; the master
     * process itself has no reason to drop what it has not reported yet.
     */
    public static function reset(): void
    {
        self::$windows = [];
    }

    /**
     * Tells whether this failure is still written in full, and counts it either way.
     *
     * A window belongs to one exception class on one server, so a peer channel losing
     * links cannot silence the refusals a browser port is writing at the same time.
     *
     * @param string $serverName Name of the server the client belongs to
     * @param Throwable $failure Failure the read ended with
     * @param float $now Current time, as the caller reads it
     * @return bool True when the line is written rather than held back
     */
    private static function admits(string $serverName, Throwable $failure, float $now): bool
    {
        $failureClass = get_class($failure);
        $key = $failureClass . ' ' . $serverName;
        $window = self::$windows[$key] ?? null;

        if ($window === null || $now - $window['openedAt'] >= self::WINDOW_SECONDS) {
            if ($window !== null) {
                self::summarize($window);
            }

            self::$windows[$key] = [
                'openedAt' => $now,
                'written' => 1,
                'held' => 0,
                'failureClass' => $failureClass,
                'serverName' => $serverName,
            ];

            return true;
        }

        if ($window['written'] < self::BURST_LINES) {
            self::$windows[$key]['written']++;
            return true;
        }

        self::$windows[$key]['held']++;

        return false;
    }

    /**
     * Writes what a window held back, if it held anything back at all.
     *
     * @param array{openedAt: float, written: int, held: int, failureClass: string, serverName: string} $window
     *     Window being closed
     */
    private static function summarize(array $window): void
    {
        if ($window['held'] === 0) {
            return;
        }

        Logger::warning(sprintf(
            self::SUMMARY_FORMAT,
            $window['held'],
            $window['failureClass'],
            $window['serverName'],
            (int)self::WINDOW_SECONDS
        ));
    }
}
