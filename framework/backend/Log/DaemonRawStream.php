<?php

declare(strict_types=1);

namespace Hilos\Log;

use Hilos\Core\Daemon\DockerManager;

/**
 * The one place that names the daemon's raw output streams (HIL-480).
 *
 * `proc_open` gives the daemon's stdout and stderr files rather than pipes, and a descriptor
 * follows the inode it was opened on: once rotation renames `daemon.log` away, everything PHP
 * prints past the Logger — a fatal, a warning, a trace — lands in the closed batch while the live
 * file stays empty. The raw output therefore gets a pair of files of its own, derived from the two
 * env paths, which runtime rotation leaves in place and only a daemon restart replaces.
 *
 * Three callers have to agree on that name: {@see DockerManager} pointing the descriptors at it,
 * {@see LogStoreReader} classifying it as a daemon stream, and {@see LogRotator} keeping it out of
 * a batch. Three places computing it separately would part ways on the first edit, so the name is
 * derived here and nowhere else, and it is derived rather than configured: a setting could aim the
 * raw output back at the file the Logger writes, which is the stuck descriptor all over again.
 */
final class DaemonRawStream
{
    /** Inserted before the extension of the stream the raw pair belongs to. */
    public const string SUFFIX = '-raw';

    /**
     * @param string $streamPath Path of the Logger-written stream, as the env names it
     * @return string Path of the raw stream beside it
     */
    public static function pathFor(string $streamPath): string
    {
        $basename = basename($streamPath);
        // The directory is kept as the prefix of the original string: rebuilding it from dirname()
        // means gluing a separator back on, which turns '/daemon.log' into '//daemon-raw.log' and a
        // bare 'daemon.log' into './daemon-raw.log'.
        $directory = substr($streamPath, 0, strlen($streamPath) - strlen($basename));

        $extensionAt = strrpos($basename, '.');
        if ($extensionAt === false) {
            return $directory . $basename . self::SUFFIX . '.log';
        }

        return $directory . substr($basename, 0, $extensionAt) . self::SUFFIX . substr($basename, $extensionAt);
    }
}
