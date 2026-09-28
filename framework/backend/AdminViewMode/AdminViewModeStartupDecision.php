<?php

declare(strict_types=1);

namespace Hilos\AdminViewMode;

/**
 * AdminViewModeStartupDecision - what one start of a node does about the admin view mode (HIL-1249).
 *
 * A bare yes or no is not enough: the start also writes the halves of the latch and says in the
 * journal why the mode is what it is, and all of that follows from the same four facts. So
 * {@see AdminViewModeStartupVerdict} answers with every consequence at once, and
 * {@see AdminViewModeStartup} only carries them out.
 */
final readonly class AdminViewModeStartupDecision
{
    /**
     * @param bool $enabled Whether the mode is on for this node until it restarts
     * @param bool $writeFile Whether the file half of the latch is to be written - closed now, or written back
     * @param bool $writeRow Whether the row half of the latch is to be written - closed now, or written back
     * @param bool $conflict Whether the variable asks for the mode on a latched installation (an ERROR line)
     * @param bool $closedForGood Whether this start is the one that closes the mode on the installation
     * @param bool $onInProduction Whether the mode is on in production (a WARNING line)
     */
    public function __construct(
        public bool $enabled,
        public bool $writeFile = false,
        public bool $writeRow = false,
        public bool $conflict = false,
        public bool $closedForGood = false,
        public bool $onInProduction = false,
    ) {
    }
}
