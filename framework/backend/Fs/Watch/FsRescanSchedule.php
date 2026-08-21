<?php

declare(strict_types=1);

namespace Hilos\Fs\Watch;

/**
 * Decides WHEN a consumer of {@see FsWatchInterface} re-reads what it watches.
 *
 * Two rules, one answer. A change never causes a read of its own: the first one opens a
 * fixed window and the read happens when it closes, so a burst of events costs one read
 * ({@see COALESCE_WINDOW_SECONDS}). And a read happens anyway on a period, whether or not
 * anything was reported ({@see RESCAN_FLOOR_SECONDS}), because no engine sees everything -
 * a dropped inotify queue, a network filesystem that announces nothing, a file rewritten in
 * place under a directory whose mtime never moved.
 *
 * **The window is fixed, not "until it goes quiet".** A quiet-until-settled window starves
 * under a continuous stream and fires never; a fixed one has bounds that can be stated -
 * latency at most one window, rate at most one read per window.
 *
 * **The clock is a parameter, not a dependency.** Every method is told what time it is, so
 * the policy is decided by whoever owns the tick and this object can be tested at any speed
 * without a sleep and without a seam over the clock.
 */
final class FsRescanSchedule
{
    /**
     * How long the first unreported change waits for its neighbours.
     *
     * One second: a publish lands as several events within milliseconds, and a read that
     * costs re-parsing every file in the tree must not run once per event.
     */
    public const float COALESCE_WINDOW_SECONDS = 1.0;

    /**
     * Longest a consumer goes without re-reading, however quiet the watch is.
     *
     * Five minutes: the ceiling on how stale an index may be when the engine saw nothing at
     * all, cheap enough to pay unconditionally against a tick budget measured in
     * milliseconds.
     */
    public const float RESCAN_FLOOR_SECONDS = 300.0;

    /** @var float Microtime the open window started at; zero when no change is pending */
    private float $pendingSince = 0.0;

    /** @var float Microtime of the last read, which is what the period is measured from */
    private float $scannedAt;

    /**
     * @param float $now Microtime the schedule starts counting its period from
     */
    public function __construct(float $now)
    {
        $this->scannedAt = $now;
    }

    /**
     * Records that the watch reported something, opening the window if it is not open yet.
     *
     * Later changes inside an open window are absorbed by it - that is the whole point of a
     * fixed window - so this is cheap to call on every tick that reports anything.
     *
     * @param float $now Current microtime
     */
    public function noteChanges(float $now): void
    {
        if ($this->pendingSince !== 0.0) {
            return;
        }

        $this->pendingSince = $now;
    }

    /**
     * Records that the consumer has just re-read everything, closing any open window.
     *
     * Called by whoever performs the read, whatever made it happen: a scan an operator asked
     * for counts as much as one this schedule asked for, and both restart the period.
     *
     * @param float $now Current microtime
     */
    public function noteScan(float $now): void
    {
        $this->pendingSince = 0.0;
        $this->scannedAt = $now;
    }

    /**
     * @param float $now Current microtime
     * @return bool True when the consumer should re-read now
     */
    public function isDue(float $now): bool
    {
        if ($this->pendingSince !== 0.0 && ($now - $this->pendingSince) >= self::COALESCE_WINDOW_SECONDS) {
            return true;
        }

        return ($now - $this->scannedAt) >= self::RESCAN_FLOOR_SECONDS;
    }
}
