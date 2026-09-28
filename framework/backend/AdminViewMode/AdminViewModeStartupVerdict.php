<?php

declare(strict_types=1);

namespace Hilos\AdminViewMode;

use Hilos\Environment\NonProductionGate;

/**
 * AdminViewModeStartupVerdict - whether the admin view mode is on for a node that is starting (HIL-1249).
 *
 * The mode works in production. It was made for the demos, and the demos live in production, so
 * a production node started with the variable on and no latch has the mode on - that is the
 * branch below where it opens. What production adds is the latch: a production node that starts
 * with the mode off closes it for good, and from then on the variable is overruled.
 *
 * - A stand ({@see NonProductionGate} admits it) has the mode the variable says, and the latch is
 *   neither read nor written there.
 * - Production with neither half of the latch: the variable off closes the mode - both halves are
 *   written; the variable on opens it, with a WARNING in the journal.
 * - Production with one half: the mode is off, and the missing half is written back from the other.
 * - Production with both halves: the mode is off.
 * - Production with a latch and the variable on is the conflict: the mode is off, and the journal
 *   says so on every start.
 *
 * Pure function of its inputs: no facade reads, no filesystem, no database. The reads happen in
 * {@see AdminViewModeStartup}, which also carries out what this decides.
 */
final class AdminViewModeStartupVerdict
{
    /**
     * Decides the mode of a starting node and what the start owes the latch.
     *
     * @param bool $stand Whether the node is a stand rather than production
     * @param bool $variableOn Whether HILOS_ADMIN_VIEW_MODE_ENABLED asks for the mode
     * @param bool $fileLatched Whether the latch file is in the node's log directory; false on a stand
     * @param bool $rowLatched Whether the latch row is in the database; false on a stand
     * @return AdminViewModeStartupDecision The mode and every consequence of it
     */
    public static function decide(bool $stand, bool $variableOn, bool $fileLatched, bool $rowLatched): AdminViewModeStartupDecision
    {
        if ($stand) {
            return new AdminViewModeStartupDecision($variableOn);
        }

        if (!$fileLatched && !$rowLatched) {
            return $variableOn
                ? new AdminViewModeStartupDecision(true, onInProduction: true)
                : new AdminViewModeStartupDecision(false, writeFile: true, writeRow: true, closedForGood: true);
        }

        return new AdminViewModeStartupDecision(
            false,
            writeFile: !$fileLatched,
            writeRow: !$rowLatched,
            conflict: $variableOn,
        );
    }
}
