<?php

declare(strict_types=1);

namespace Hilos\Fs\Watch;

use Hilos\Fs\Exception\DirectoryWatchException;

/**
 * The one entry to filesystem watching: opens a watch and picks the engine behind it.
 *
 * The choice is made here, once, and never surfaces again - {@see FsWatchInterface} is all a
 * consumer holds, so no caller has to ask "is the extension loaded" and no two callers can
 * answer it differently. That is why the fallback is a working engine rather than an inert
 * one: an engine that reports nothing would put the branch back into every consumer, in the
 * form of a comment explaining why the list is sometimes stale.
 *
 * Static because opening a watch has no state to configure: what to watch is said afterwards,
 * through the instance.
 */
final class FsWatch
{
    /**
     * Opens a watch on this node, kernel-backed where that is possible.
     *
     * Anything short of a live inotify instance - the extension absent, the instance refused,
     * a descriptor that cannot be made non-blocking - lands on the polling engine, because a
     * consumer asking for a watch has no fallback of its own to fall back to.
     *
     * @return FsWatchInterface Watch with no directories in it yet
     */
    public static function open(): FsWatchInterface
    {
        if (!extension_loaded('inotify')) {
            return new PollingFsWatch();
        }

        // warning-suppressed: false is answered by the polling engine below
        $instance = @inotify_init();
        if ($instance === false) {
            return new PollingFsWatch();
        }

        try {
            return new InotifyFsWatch($instance);
        } catch (DirectoryWatchException) {
            fclose($instance);

            return new PollingFsWatch();
        }
    }
}
