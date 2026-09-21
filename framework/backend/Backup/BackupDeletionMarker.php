<?php

declare(strict_types=1);

namespace Hilos\Backup;

use Hilos\Fs\Exception\FileDeleteException;
use Hilos\Fs\FsException;
use Hilos\Fs\FsPath;

/**
 * The durable fact that a locally deleted backup is still owed a delete on the receiver.
 *
 * An empty file inside the scope directory, named `<base>.deleted`. The name is the whole
 * content: the mirror pass globs these and includes exactly those pair names. No RT or
 * index field holds this, because the index is rebuilt from files on every start and a
 * debt stored there would die on restart.
 *
 * Empty on purpose: there is no payload a walk could read half of, so the write is a
 * direct create rather than a temp file plus rename.
 *
 * The scanner globs only `*.json` and `*.tar.gz`, so the index, rotation and the occupancy
 * count never see a marker.
 *
 * Static because a marker has no state of its own: it is addressed by the directory it
 * lives in, one call per backup, the way {@see FsPath} addresses a file by its path.
 */
final class BackupDeletionMarker
{
    /** Suffix of the marker beside the pair it names; not `*.json` or `*.tar.gz`, so no scan counts it. */
    public const string EXTENSION = '.deleted';

    /**
     * Absolute path of the marker for one backup pair.
     *
     * @param string $scopeDir Absolute path of the scope directory the pair lived in
     * @param string $base Archive/sidecar base name without extension
     * @return string Absolute path of the empty marker file
     */
    public static function path(string $scopeDir, string $base): string
    {
        return $scopeDir . '/' . $base . self::EXTENSION;
    }

    /**
     * Records that this pair is owed a delete on the receiver.
     *
     * @param string $scopeDir Absolute path of the scope directory the pair lived in
     * @param string $base Archive/sidecar base name without extension
     * @return bool True when the empty file was written; false when the write failed
     */
    public static function write(string $scopeDir, string $base): bool
    {
        try {
            FsPath::write(self::path($scopeDir, $base), '');
        } catch (FsException) {
            return false;
        }

        return true;
    }

    /**
     * Base names whose pair is still owed a delete on the receiver.
     *
     * @param string $scopeDir Absolute path of the scope directory to glob
     * @return list<string> Base names taken from `*.deleted` files; order is not significant
     */
    public static function owed(string $scopeDir): array
    {
        $paths = glob($scopeDir . '/*' . self::EXTENSION) ?: [];
        $bases = [];
        foreach ($paths as $path) {
            $bases[] = basename($path, self::EXTENSION);
        }

        return $bases;
    }

    /**
     * Drops the named markers after a successful mirror pass covered them.
     *
     * A name that is already gone is not an error: two passes covering the same debt
     * both meant the state this leaves behind, and {@see FsPath::delete()} says so by
     * doing nothing.
     *
     * @param string $scopeDir Absolute path of the scope directory the markers live in
     * @param list<string> $bases Base names whose markers this pass covered
     * @throws FileDeleteException When a marker is there and cannot be removed
     */
    public static function clear(string $scopeDir, array $bases): void
    {
        foreach ($bases as $base) {
            FsPath::delete(self::path($scopeDir, $base));
        }
    }
}
