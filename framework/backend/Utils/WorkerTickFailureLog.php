<?php

declare(strict_types=1);

namespace Hilos\Utils;

use Hilos\Core\Daemon\ContainedFailure;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Exception\MalformedInput;

/**
 * Writes the journal line for a failure the worker tick contained.
 *
 * The tick of {@see WorkerManager::run()} skips the unit of work that failed and keeps
 * going, so the journal is the only place the failure surfaces at all: without this line
 * a broken page declaration or a throwing agent would be indistinguishable from nothing
 * happening. What the line says therefore lives here, next to the limit on how often it
 * may repeat, and not at the six call sites of the guard.
 *
 * The level is always an error, which is where this writer parts company with
 * {@see ClientReadFailureLog}: the master reads an open port, where a line that does not
 * parse is the daily work of the internet and reaches the journal as a warning, while a
 * worker's only correspondent is its own daemon and its only work is the project's own
 * code. Nothing that reaches this writer is routine, so the {@see MalformedInput} marker
 * is not consulted here.
 *
 * A cause that does not go away repeats on every tick, so the lines are limited the way
 * the master limits its own, by the shared {@see RepeatedFailureWindows}. The key is the
 * exception class and the unit, and deliberately not the address: one broken declaration
 * is seen by every subscription that reads it, and an address in the key would open a
 * window per subscriber and let twenty copies of one mistake through the limit exactly
 * when it is needed most.
 *
 * The windows are one static instance of the worker process, which runs one tick at a
 * time; no runtime collection and no lock is involved.
 */
class WorkerTickFailureLog
{
    /** Number of failures per key written in full before a window starts counting instead */
    public const int BURST_LINES = 3;

    /** Length of the window a key's failures are counted over, in seconds */
    public const float WINDOW_SECONDS = 60.0;

    /** Journal line for one contained failure: worker, unit, address, exception class, file, line, message */
    private const string ENTRY_FORMAT = 'Worker #%d contained a failure in %s (%s): %s in %s:%d - %s';

    /** Journal line closing a window: held-back count, exception class, unit, worker, window length */
    private const string SUMMARY_FORMAT = 'Suppressed %d more %s failures in %s on worker #%d in the last %d seconds';

    /** @var ?RepeatedFailureWindows Limiter of this worker, built on first use */
    private static ?RepeatedFailureWindows $windows = null;

    /**
     * Writes the journal line for a failure the tick contained.
     *
     * @param int $workerIndex Index of the worker whose tick contained the failure
     * @param ContainedFailure $failure Failure, the unit it belongs to and where it happened
     * @param float $now Current time, as the caller reads it
     */
    public static function write(int $workerIndex, ContainedFailure $failure, float $now): void
    {
        $entry = sprintf(
            self::ENTRY_FORMAT,
            $workerIndex,
            $failure->unit->value,
            $failure->address,
            get_class($failure->failure),
            basename($failure->failure->getFile()),
            $failure->failure->getLine(),
            $failure->failure->getMessage()
        );

        // Hand over what the limiter has finished counting before this failure is judged,
        // so a window that ran out is summarized above the line that opens its successor.
        self::flushClosedWindows($workerIndex, $now);

        if (self::windows()->admits(self::windowKey($failure), $now)) {
            Logger::error($entry);
        }
    }

    /**
     * Writes the summary of every window whose length has run out, and forgets it.
     *
     * Called from the worker loop rather than from the next failure of the same kind: a
     * stream that stopped would otherwise leave its tail uncounted until something of
     * that exact kind failed again, which for a cause that has been fixed is never.
     *
     * @param int $workerIndex Index of the worker whose windows are being closed
     * @param float $now Current time, as the caller reads it
     */
    public static function flushClosedWindows(int $workerIndex, float $now): void
    {
        foreach (self::windows()->closeExpired($now) as $closed) {
            self::summarize($workerIndex, $closed['key'], $closed['held']);
        }
    }

    /**
     * Forgets every open window without writing its summary.
     *
     * For a test that needs the next case to start counting from nothing; the worker
     * itself has no reason to drop what it has not reported yet.
     */
    public static function reset(): void
    {
        self::windows()->reset();
    }

    /**
     * @return RepeatedFailureWindows Limiter of this worker, created on first use
     */
    private static function windows(): RepeatedFailureWindows
    {
        return self::$windows ??= new RepeatedFailureWindows(self::BURST_LINES, self::WINDOW_SECONDS);
    }

    /**
     * Names what counts as the same failure repeating.
     *
     * @param ContainedFailure $failure Failure the tick contained
     * @return string Key the limiter counts this failure under
     */
    private static function windowKey(ContainedFailure $failure): string
    {
        return get_class($failure->failure) . ' ' . $failure->unit->value;
    }

    /**
     * Writes what a window held back, if it held anything back at all.
     *
     * @param int $workerIndex Index of the worker whose window closed
     * @param string $key Key of the closed window, as {@see self::windowKey()} built it
     * @param int $held Number of lines the window held back
     */
    private static function summarize(int $workerIndex, string $key, int $held): void
    {
        if ($held === 0) {
            return;
        }

        // Back into the pair the window belongs to: a class name carries no space, so
        // whatever follows the first one is the unit, which is named in words and has one.
        [$failureClass, $unit] = explode(' ', $key, 2);

        Logger::error(sprintf(
            self::SUMMARY_FORMAT,
            $held,
            $failureClass,
            $unit,
            $workerIndex,
            (int)self::WINDOW_SECONDS
        ));
    }
}
