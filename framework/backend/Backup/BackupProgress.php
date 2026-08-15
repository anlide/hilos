<?php

declare(strict_types=1);

namespace Hilos\Backup;

/**
 * BackupProgress - the percentage and the time left of a running backup or restore.
 *
 * Pure arithmetic over the three anchors the runtime row carries (the phase, the instant it
 * started, and the estimated duration of the whole run): nothing is read from a clock or a disk
 * here, so the caller decides what "now" means. The same formula is implemented in the frontend
 * core, and the numbers of both unit suites are kept identical on purpose - that pairing is the
 * only thing holding the two implementations together.
 *
 * A run whose duration cannot be estimated has no percentage at all rather than a made-up one:
 * the surfaces show the phase name and a spinner instead.
 */
final class BackupProgress
{
    /** Percent is reported as a whole number of hundredths of a run. */
    private const int PERCENT_SCALE = 100;

    /** A run still going never shows a full bar, whatever the arithmetic says. */
    private const int MAX_RUNNING_PERCENT = 99;

    /**
     * How far along a run is, in whole percent.
     *
     * The phases before the current one are counted whole; the current one contributes the share
     * of its own budget that has already elapsed, capped at that budget. The result is clamped to
     * the current phase's own span, so a wrong clock - the browser's, against the server's - moves
     * the bar to the edge of the phase rather than somewhere absurd.
     *
     * @param float $weightBefore Share of the run completed before the current phase (0..1)
     * @param float $weight Share of the run the current phase is expected to take (0..1)
     * @param float $phaseElapsedSeconds Seconds since the current phase started
     * @param ?int $estimatedSeconds Estimated duration of the whole run; null when there is no history to estimate from
     * @return ?int Percent completed, or null when the run cannot be estimated
     */
    public static function percent(
        float $weightBefore,
        float $weight,
        float $phaseElapsedSeconds,
        ?int $estimatedSeconds,
    ): ?int {
        if ($estimatedSeconds === null || $estimatedSeconds <= 0) {
            return null;
        }

        if ($weightBefore >= 1.0) {
            return self::PERCENT_SCALE;
        }

        $phaseBudgetSeconds = $estimatedSeconds * $weight;
        $withinPhase = $phaseBudgetSeconds > 0.0
            ? min(1.0, max(0.0, $phaseElapsedSeconds) / $phaseBudgetSeconds)
            : 0.0;

        $reached = (int)floor(($weightBefore + $weight * $withinPhase) * self::PERCENT_SCALE);
        $phaseFloor = (int)floor($weightBefore * self::PERCENT_SCALE);
        $phaseCeiling = min(
            self::MAX_RUNNING_PERCENT,
            (int)floor(($weightBefore + $weight) * self::PERCENT_SCALE),
        );

        return max($phaseFloor, min($phaseCeiling, $reached));
    }

    /**
     * How many seconds the run is still expected to take.
     *
     * A run that outlives its estimate returns a negative number rather than a frozen zero: both
     * lie to the operator, but only one of them can be told apart from "almost done", and the
     * surfaces turn it into "longer than usual".
     *
     * @param ?int $estimatedSeconds Estimated duration of the whole run; null when there is no history to estimate from
     * @param float $totalElapsedSeconds Seconds since the run started
     * @return ?int Seconds left, negative once the estimate is spent; null when the run cannot be estimated
     */
    public static function remainingSeconds(?int $estimatedSeconds, float $totalElapsedSeconds): ?int
    {
        if ($estimatedSeconds === null || $estimatedSeconds <= 0) {
            return null;
        }

        return (int)ceil($estimatedSeconds - $totalElapsedSeconds);
    }
}
