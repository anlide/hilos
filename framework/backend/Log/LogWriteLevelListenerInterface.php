<?php

declare(strict_types=1);

namespace Hilos\Log;

use Hilos\Utils\LogLevel;

/**
 * Told when the write level of this process actually changed (HIL-761).
 *
 * One implementation exists and one is expected to: the worker's reporter, which passes the new
 * level on to the master process. The master cannot read the setting itself - it is forbidden the
 * database - so somebody who can has to say it out loud, and this is the seam that lets the
 * applier stay unaware of workers, sockets and message types.
 *
 * Called only on a real change, never on a re-application of the same level: a node with several
 * workers would otherwise say the same thing as many times as it has them.
 */
interface LogWriteLevelListenerInterface
{
    /**
     * Reacts to the write level of this process having changed.
     *
     * @param LogLevel $level Level the process writes from now on
     */
    public function onWriteLevelChanged(LogLevel $level): void;
}
