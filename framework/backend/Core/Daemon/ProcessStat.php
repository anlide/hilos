<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

/**
 * The two /proc/<pid>/stat fields the orphan sweep decides on.
 *
 * A zombie is kept apart from a live process on purpose: it holds no ports and cannot
 * be signalled, so treating it as an orphan would make {@see OrphanReaper} wait out its
 * whole grace period and then SIGKILL a process that already exited.
 */
final class ProcessStat
{
    /**
     * @param int $parentPid Parent process id
     * @param bool $isZombie Whether the process already exited and only awaits collection
     */
    public function __construct(
        public readonly int $parentPid,
        public readonly bool $isZombie,
    ) {
    }
}
