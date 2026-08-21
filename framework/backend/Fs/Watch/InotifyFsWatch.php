<?php

declare(strict_types=1);

namespace Hilos\Fs\Watch;

use Hilos\Fs\Exception\DirectoryWatchException;
use Hilos\Utils\Logger;

/**
 * The kernel-backed engine: ext-inotify tells us what moved, we only drain its queue.
 *
 * Chosen by {@see FsWatch::open()} wherever the extension is loaded. The instance is a
 * non-blocking stream, so draining it costs one syscall on an idle tick and never parks
 * the worker loop.
 *
 * **{@see EVENT_MASK} leaves IN_MODIFY out on purpose.** Everything this framework writes
 * into a watched directory is published as a temp file and a rename, so entry-level events
 * describe a publish completely: a multi-gigabyte archive costs four events instead of the
 * thousands its writes would raise. A file rewritten in place is still reported, once, when
 * its writer closes it (IN_CLOSE_WRITE is in the mask). What the omission really costs is a
 * file held open and appended to indefinitely - a log - which stays silent until its handle
 * is closed; the consumer's periodic rescan is what covers that ({@see FsRescanSchedule}).
 *
 * **Two events arrive without being asked for, and both are handled.** IN_Q_OVERFLOW says
 * the kernel dropped events, which turns into "every watched directory changed" plus a
 * warning naming it. IN_IGNORED says a watch descriptor died with its directory - the
 * entry is dropped here so the consumer's next reconciliation puts a directory that came
 * back under watch again, rather than holding a descriptor the kernel has already forgotten.
 */
final class InotifyFsWatch implements FsWatchInterface
{
    /**
     * Entry-level events only: something was created, removed, renamed in or out, or
     * finished being written. IN_ONLYDIR makes the kernel refuse a path that is not a
     * directory instead of watching a single file by accident.
     */
    public const int EVENT_MASK = IN_CREATE | IN_DELETE | IN_MOVED_TO | IN_MOVED_FROM | IN_CLOSE_WRITE | IN_ONLYDIR;

    /** @var array<string, int> Watched directory => its watch descriptor */
    private array $descriptors = [];

    /** @var array<int, string> Watch descriptor => the directory it stands for */
    private array $directories = [];

    /** @var array<string, true> Watched directories reported changed since the last drain */
    private array $changed = [];

    /** @var bool Whether the kernel reported a dropped-event overflow since the last drain */
    private bool $overflowed = false;

    /**
     * @param resource $instance Inotify instance from `inotify_init()`
     * @throws DirectoryWatchException When the instance cannot be made non-blocking
     */
    public function __construct(private $instance)
    {
        if (!stream_set_blocking($this->instance, false)) {
            throw new DirectoryWatchException('Cannot switch the inotify instance to non-blocking mode');
        }
    }

    /**
     * @param string $directory Absolute path of an existing directory
     * @throws DirectoryWatchException When the path is not a directory or the watch cannot be added
     */
    public function watch(string $directory): void
    {
        if (isset($this->descriptors[$directory])) {
            return;
        }

        if (!is_dir($directory)) {
            throw new DirectoryWatchException("Cannot watch, not a directory: {$directory}");
        }

        // warning-suppressed: false becomes DirectoryWatchException on the next line
        $descriptor = @inotify_add_watch($this->instance, $directory, self::EVENT_MASK);
        if ($descriptor === false) {
            throw new DirectoryWatchException("Cannot watch directory: {$directory}");
        }

        $this->descriptors[$directory] = $descriptor;
        $this->directories[$descriptor] = $directory;
    }

    /**
     * @param string $directory Absolute path as it was passed to {@see watch()}
     */
    public function unwatch(string $directory): void
    {
        $descriptor = $this->descriptors[$directory] ?? null;
        if ($descriptor === null) {
            return;
        }

        // warning-suppressed: a descriptor the kernel already dropped is the outcome asked for here
        @inotify_rm_watch($this->instance, $descriptor);

        unset($this->descriptors[$directory], $this->directories[$descriptor], $this->changed[$directory]);
    }

    /**
     * @return list<string> Absolute paths currently under watch
     */
    public function watched(): array
    {
        return array_keys($this->descriptors);
    }

    /**
     * @return list<string> Distinct watched directories that changed, in watch order
     */
    public function takeChanged(): array
    {
        $this->drain();

        if ($this->overflowed) {
            $this->overflowed = false;
            $this->changed = [];

            return $this->watched();
        }

        $changed = array_keys($this->changed);
        $this->changed = [];

        return $changed;
    }

    public function discardPending(): void
    {
        $this->drain();

        $this->overflowed = false;
        $this->changed = [];
    }

    public function close(): void
    {
        // Idempotent because a carrier may close on shutdown and be torn down afterwards;
        // a stream already closed reads as no resource at all.
        if (is_resource($this->instance)) {
            fclose($this->instance);
        }

        $this->descriptors = [];
        $this->directories = [];
        $this->changed = [];
        $this->overflowed = false;
    }

    /**
     * Reads whatever the kernel has queued and files it against the watched set.
     *
     * The queue length is asked for first because reading an empty non-blocking instance
     * is a warning rather than an empty answer, and an idle tick is the common case.
     */
    private function drain(): void
    {
        while (inotify_queue_len($this->instance) > 0) {
            $events = inotify_read($this->instance);
            if ($events === false) {
                return;
            }

            foreach ($events as $event) {
                $this->fileEvent((int)$event['wd'], (int)$event['mask']);
            }
        }
    }

    /**
     * Records one event against the directory its descriptor stands for.
     *
     * @param int $descriptor Watch descriptor the event arrived on (-1 on an overflow)
     * @param int $mask Event mask as the kernel reported it
     */
    private function fileEvent(int $descriptor, int $mask): void
    {
        if (($mask & IN_Q_OVERFLOW) !== 0) {
            Logger::warning('Filesystem watch: the inotify queue overflowed, rescanning every watched directory');
            $this->overflowed = true;

            return;
        }

        $directory = $this->directories[$descriptor] ?? null;
        if ($directory === null) {
            return;
        }

        if (($mask & IN_IGNORED) !== 0) {
            unset($this->descriptors[$directory], $this->directories[$descriptor]);
            $this->changed[$directory] = true;

            return;
        }

        $this->changed[$directory] = true;
    }
}
