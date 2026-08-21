<?php

declare(strict_types=1);

namespace Hilos\Fs\Watch;

use Hilos\Fs\Exception\DirectoryWatchException;

/**
 * The portable engine: one stat per watched directory, once a second.
 *
 * Chosen by {@see FsWatch::open()} wherever ext-inotify is absent, so that a consumer never
 * carries a branch for "this node has no clock". It answers the same question as
 * {@see InotifyFsWatch} at the same observable promptness ({@see POLL_INTERVAL_SECONDS}
 * matches the coalescing window a consumer waits out anyway), and it costs one syscall per
 * directory per second whatever the directory holds.
 *
 * **Directory mtime, never a per-file stat.** The cost of an engine that lives in the
 * framework must not grow with the number of files a later consumer puts under it - a log
 * or upload directory holds thousands where a backup scope holds tens. A directory's mtime
 * moves when an entry is created, removed or renamed, which is every write this framework
 * publishes, and it does NOT move when an existing file is rewritten in place. This engine
 * is blinder than {@see InotifyFsWatch} in exactly that spot - the kernel reports a rewrite
 * on close, an mtime cannot - and the consumer's periodic rescan is what covers it
 * ({@see FsRescanSchedule}).
 *
 * **A directory that disappeared is a change, not an error.** Its stat stops answering and
 * the mtime is remembered as absent, so the directory being created again reads as the next
 * change rather than as silence.
 */
final class PollingFsWatch implements FsWatchInterface
{
    /**
     * How long a poll of the whole watched set is good for.
     *
     * One second because the observable promptness a consumer gets is bounded by its own
     * coalescing window, which is that long as well: polling faster would buy latency
     * nobody sees, and mtime is a whole-second value on most filesystems anyway.
     */
    public const float POLL_INTERVAL_SECONDS = 1.0;

    /** Remembered mtime of a watched directory that is not there. */
    private const int MTIME_ABSENT = -1;

    /** @var array<string, int> Watched directory => mtime as of the last poll */
    private array $mtimes = [];

    /** @var float Microtime of the last poll; zero until the first one */
    private float $polledAt = 0.0;

    /**
     * @param string $directory Absolute path of an existing directory
     * @throws DirectoryWatchException When the path is not a directory
     */
    public function watch(string $directory): void
    {
        if (array_key_exists($directory, $this->mtimes)) {
            return;
        }

        if (!is_dir($directory)) {
            throw new DirectoryWatchException("Cannot watch, not a directory: {$directory}");
        }

        $this->mtimes[$directory] = $this->readMtime($directory);
    }

    /**
     * @param string $directory Absolute path as it was passed to {@see watch()}
     */
    public function unwatch(string $directory): void
    {
        unset($this->mtimes[$directory]);
    }

    /**
     * @return list<string> Absolute paths currently under watch
     */
    public function watched(): array
    {
        return array_keys($this->mtimes);
    }

    /**
     * @return list<string> Distinct watched directories that changed, in watch order
     */
    public function takeChanged(): array
    {
        $now = microtime(true);
        if (($now - $this->polledAt) < self::POLL_INTERVAL_SECONDS) {
            return [];
        }
        $this->polledAt = $now;

        $changed = [];
        foreach ($this->mtimes as $directory => $mtime) {
            $current = $this->readMtime($directory);
            if ($current !== $mtime) {
                $this->mtimes[$directory] = $current;
                $changed[] = $directory;
            }
        }

        return $changed;
    }

    /**
     * Re-reads every watched directory without reporting, so the next poll compares against now.
     *
     * The poll clock is reset with it, which is deliberate: a consumer discards only when it
     * is about to read everything, and a write landing right after that read must be free to
     * wake the very next poll instead of waiting out an interval that was already spent.
     */
    public function discardPending(): void
    {
        foreach (array_keys($this->mtimes) as $directory) {
            $this->mtimes[$directory] = $this->readMtime($directory);
        }

        $this->polledAt = 0.0;
    }

    public function close(): void
    {
        $this->mtimes = [];
        $this->polledAt = 0.0;
    }

    /**
     * @param string $directory Absolute path of a watched directory
     * @return int Directory mtime, or {@see MTIME_ABSENT} when it cannot be stat'd
     */
    private function readMtime(string $directory): int
    {
        clearstatcache(true, $directory);

        // warning-suppressed: false is read as "the directory is gone", which is a change like any other
        $stat = @stat($directory);

        return $stat === false ? self::MTIME_ABSENT : (int)$stat['mtime'];
    }
}
