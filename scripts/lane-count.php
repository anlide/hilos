<?php

declare(strict_types=1);

/**
 * How many lanes a full run takes on a machine nobody told it about.
 *
 * `scripts/run-test-suite.php` asks this only when neither `--lanes=N` nor
 * `HILOS_TEST_LANES` said a number. The answer is sized from what the machine
 * reports about itself — cores and available memory — rather than settled on one
 * constant, because the same command line runs on the box of the line, on a
 * developer's laptop and on a two-core CI runner, and the right number differs on
 * each: a lane per two cores, one lane left to the machine itself, and never more
 * lanes than the available memory can carry.
 *
 * The rule errs towards the serial run. Being slow is recoverable, thrashing a
 * runner is not — so a machine that reports no cores or no memory gets one lane,
 * and so does one too small to spare a lane for itself.
 *
 * The lane count decides how many steps run at once and nothing else. It used to
 * double as the timeout multiplier of every suite; it no longer does (HIL-1227),
 * and each suite reads its factor from the host it runs on.
 *
 * This file only declares functions and executes nothing, so that the runner and
 * `framework/tests/Unit/LaneCountTest.php` can both require it.
 */

/** Lanes to use on a machine too small, or too unfamiliar, to measure. */
const SERIAL_LANES = 1;

/**
 * Cores one lane is granted. Measured on nova-de (run 0656): three lanes kept the
 * load per CPU at 0.1–0.4 on sixteen cores, a lane at its peak taking about two.
 */
const ADAPTIVE_CORES_PER_LANE = 2;

/** Lanes left to the machine itself — the docker daemon, whoever else works on it. */
const ADAPTIVE_LANES_KEPT_FOR_HOST = 1;

/**
 * Available memory, in GiB, one lane is granted: the share the previous rule kept,
 * which asked for 4 GiB before it let two lanes run.
 */
const ADAPTIVE_GIB_PER_LANE = 2.0;

/**
 * The lane count for a machine of this size: a lane per
 * {@see ADAPTIVE_CORES_PER_LANE} cores less {@see ADAPTIVE_LANES_KEPT_FOR_HOST},
 * held down to what the available memory carries at {@see ADAPTIVE_GIB_PER_LANE}
 * each, and never below {@see SERIAL_LANES}.
 *
 * Pure on purpose: the two readings arrive as arguments, so the rule is tested
 * without a machine of each size to run it on.
 *
 * @param int $cores Cores the machine reports; zero when it does not say.
 * @param float|null $availableGib Available memory in GiB; null when the machine
 *     does not report it.
 * @return int Steps the run may keep in flight at once.
 */
function adaptiveLaneCount(int $cores, ?float $availableGib): int
{
    if ($cores === 0 || $availableGib === null) {
        return SERIAL_LANES;
    }

    return max(
        SERIAL_LANES,
        min(
            intdiv($cores, ADAPTIVE_CORES_PER_LANE) - ADAPTIVE_LANES_KEPT_FOR_HOST,
            (int)floor($availableGib / ADAPTIVE_GIB_PER_LANE),
        ),
    );
}
