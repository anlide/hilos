<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Fs\FsException;
use Hilos\Fs\FsPath;

/**
 * Removes files and directory trees for the test-only reset commands, and remembers what it
 * could not remove.
 *
 * One object rather than a helper in each command because the two commands that need it -
 * {@see LogsTestResetCommand} and {@see BackupTestResetCommand} - want the same two things from
 * a sweep and the same report out of it: how many paths went, and which ones stayed. The tally
 * lives here for the same reason the walk does; a caller that had to add up its own counts would
 * be the walk written twice.
 *
 * It does not suppress a failed removal. A stand whose preparation could not empty a directory
 * is a stand that will run on somebody else's leftovers, and the run after it would be judged on
 * them - so an undeletable path is named and the command that owns the sweep decides what that
 * means.
 */
final class TestPathSweeper
{
    /**
     * @var list<string> Names the sweep never removes, whatever directory it meets them in
     *
     * These are not content, they are what makes an otherwise empty directory exist at all: the
     * data roots of a demo are in git as a `.gitignore` or a `.gitkeep` and nothing else, so a
     * sweep that took them would delete a tracked file and leave the next checkout without the
     * directory the stand writes into. Found the plain way - the first run of the backup reset
     * took `demo/chat/data/backup/.gitignore` with it.
     */
    private const array KEPT_NAMES = ['.gitignore', '.gitkeep'];

    /** @var int Paths removed so far, directories counted as one each */
    private int $removed = 0;

    /** @var list<string> Paths the sweep could not remove, in the order it met them */
    private array $failed = [];

    /**
     * Removes everything inside a directory, and keeps the directory itself.
     *
     * Kept rather than removed because the node expects to find it: a log archive and a backup
     * scope are made at startup, and a stand whose preparation deleted them would spend its first
     * write making them again. A directory that is not there is nothing to do.
     *
     * @param string $directory Directory to empty
     */
    public function emptyDirectory(string $directory): void
    {
        try {
            $entries = FsPath::entries($directory);
        } catch (FsException) {
            return;
        }

        foreach ($entries as $entry) {
            if (in_array($entry, self::KEPT_NAMES, true)) {
                continue;
            }

            $this->removeTree($directory . DIRECTORY_SEPARATOR . $entry);
        }
    }

    /**
     * Removes every path a glob pattern matches, trees included.
     *
     * @param string $pattern Glob pattern, as {@see glob()} reads it
     */
    public function removeMatching(string $pattern): void
    {
        foreach (glob($pattern) ?: [] as $path) {
            if (in_array(basename($path), self::KEPT_NAMES, true)) {
                continue;
            }

            $this->removeTree($path);
        }
    }

    /**
     * @return int Paths removed by this sweeper
     */
    public function removed(): int
    {
        return $this->removed;
    }

    /**
     * @return list<string> Paths the sweeper could not remove
     */
    public function failed(): array
    {
        return $this->failed;
    }

    /**
     * Removes one path, and everything under it when it is a directory.
     *
     * What counts as removed is a path that is gone after the call. The seam's delete leaves in
     * place, without a word, what `is_file()` denies - a dangling symlink, a fifo - and a sweep
     * that counted the call rather than the outcome would report such a path as removed.
     *
     * @param string $path Path to remove
     */
    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            try {
                FsPath::delete($path);
            } catch (FsException) {
                $this->failed[] = $path;

                return;
            }
            if (file_exists($path) || is_link($path)) {
                $this->failed[] = $path;

                return;
            }
            $this->removed++;

            return;
        }

        $this->emptyDirectory($path);

        // A directory that still holds something is not a failure to report: what stayed is
        // either a name the sweep keeps on purpose, and then the directory around it has to stay
        // as well, or a file already named in `failed` - and saying so twice would make one
        // undeletable file read as two.
        if (!self::isEmptyDirectory($path)) {
            return;
        }

        try {
            FsPath::removeDirectory($path);
        } catch (FsException) {
            $this->failed[] = $path;

            return;
        }
        $this->removed++;
    }

    /**
     * Says whether a directory holds nothing at all.
     *
     * A directory that cannot be listed reads as empty, as it did under `?: []`: the removal that
     * follows is what reports it, and reporting it here as well would name one path twice.
     *
     * @param string $path Directory to look into
     * @return bool Whether the directory is empty
     */
    private static function isEmptyDirectory(string $path): bool
    {
        try {
            return FsPath::entries($path) === [];
        } catch (FsException) {
            return true;
        }
    }
}
