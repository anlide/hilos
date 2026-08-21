<?php

declare(strict_types=1);

namespace Hilos\Utils;

use Hilos\Core\Daemon\ContainedFailure;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Exception\MalformedInput;
use Hilos\Socket\Client\ClientInterface;
use Hilos\Socket\Client\WebSocketClient;
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
 * came for. The counting itself is {@see RepeatedFailureWindows}, shared with the worker
 * tick, which faces the same repetition for the same reason; the wording of both lines
 * stays here, because a summary is written for the master's readers and not for every
 * process that ever counts a repeat. The limit lives outside {@see Logger} because a
 * limiter there would quietly eat lines other subsystems count events by.
 *
 * The windows are one static instance of the master process, which is where both readers
 * run; no runtime collection and no lock is involved.
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

    /** Address of a connection whose accept key is already minted: server and key */
    private const string KEYED_ADDRESS_FORMAT = '%s acceptKey=%s';

    /** Journal line for one failed read: reader, server, exception class, file, line, message */
    private const string ENTRY_FORMAT = 'Error in %s for %s: %s in %s:%d - %s';

    /** Journal line closing a window: held-back count, exception class, server, window length */
    private const string SUMMARY_FORMAT = 'Suppressed %d more %s failures for %s in the last %d seconds';

    /** @var ?RepeatedFailureWindows Limiter for the warning branch, built on first use */
    private static ?RepeatedFailureWindows $windows = null;

    /**
     * Names one connection the way a contained failure addresses it.
     *
     * Lives beside the journal line for the reason the line does: both readers of a client
     * report the same failure, and an address built twice is an address that eventually
     * differs, leaving the project unable to tell one connection's storm from two.
     *
     * A WebSocket connection past its handshake carries the accept key, which is what a
     * project has to hold on to - it is the same identifier presence, subscriptions and
     * {@see DaemonManager::dropWebSocketConnection()} know a connection by. Before the
     * handshake there is no key to name, and the server is all the address there is.
     *
     * @param string $serverName Name of the server the connection belongs to
     * @param ClientInterface $client Connection the failure belongs to
     * @return string Address for the {@see ContainedFailure} card of this connection
     */
    public static function connectionAddress(string $serverName, ClientInterface $client): string
    {
        if (!$client instanceof WebSocketClient || $client->acceptKey === '') {
            return $serverName;
        }

        return sprintf(self::KEYED_ADDRESS_FORMAT, $serverName, $client->acceptKey);
    }

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

        // Hand over what the limiter has finished counting before this failure is judged.
        // A window that ran out is closed by the failure that replaces it, so its summary
        // stands above the line that opens the next window rather than below it.
        self::flushClosedWindows($now);

        if (self::windows()->admits(self::windowKey($serverName, $failure), $now)) {
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
        foreach (self::windows()->closeExpired($now) as $closed) {
            self::summarize($closed['key'], $closed['held']);
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
        self::windows()->reset();
    }

    /**
     * @return RepeatedFailureWindows Limiter of this process, created on first use
     */
    private static function windows(): RepeatedFailureWindows
    {
        return self::$windows ??= new RepeatedFailureWindows(self::BURST_LINES, self::WINDOW_SECONDS);
    }

    /**
     * Names what counts as the same failure repeating.
     *
     * A window belongs to one exception class on one server, so a peer channel losing
     * links cannot silence the refusals a browser port is writing at the same time.
     *
     * @param string $serverName Name of the server the client belongs to
     * @param Throwable $failure Failure the read ended with
     * @return string Key the limiter counts this failure under
     */
    private static function windowKey(string $serverName, Throwable $failure): string
    {
        return get_class($failure) . ' ' . $serverName;
    }

    /**
     * Writes what a window held back, if it held anything back at all.
     *
     * @param string $key Key of the closed window, as {@see self::windowKey()} built it
     * @param int $held Number of lines the window held back
     */
    private static function summarize(string $key, int $held): void
    {
        if ($held === 0) {
            return;
        }

        // Back into the pair the window belongs to: a class name carries no space, so
        // whatever follows the first one is the server, whether or not it has spaces too.
        [$failureClass, $serverName] = explode(' ', $key, 2);

        Logger::warning(sprintf(
            self::SUMMARY_FORMAT,
            $held,
            $failureClass,
            $serverName,
            (int)self::WINDOW_SECONDS
        ));
    }
}
