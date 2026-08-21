<?php

declare(strict_types=1);

namespace Hilos\Fs\Watch;

use Hilos\Fs\Exception\DirectoryWatchException;

/**
 * A set of directories under watch, answering "which of them changed since I last asked".
 *
 * The door a consumer sees, and the only one: it never learns which engine is behind it
 * ({@see FsWatch::open()} decides that once), so a node without ext-inotify runs the same
 * code path as a node with it. Watches are per directory and NOT recursive - a consumer
 * that needs a tree names every directory of it and reconciles the set as directories
 * appear and vanish.
 *
 * **What a change means here is deliberately coarse.** The answer is a directory, never a
 * file and never a kind of event: both engines can say that a directory's entries moved,
 * only one of them can say how, and a consumer written against the richer answer would
 * break on the other node. Whoever needs the detail re-reads the directory.
 *
 * **The queue is drained by asking.** {@see takeChanged()} returns what accumulated and
 * forgets it, so two calls in a row report a change once; {@see discardPending()} forgets
 * without reporting, which is how a consumer that is about to read everything anyway keeps
 * its own writes from waking it a second time.
 */
interface FsWatchInterface
{
    /**
     * Takes one directory under watch, or does nothing when it already is.
     *
     * @param string $directory Absolute path of an existing directory
     * @throws DirectoryWatchException When the path is not a directory or the watch cannot be added
     */
    public function watch(string $directory): void;

    /**
     * Drops one directory from the watched set, ignoring one that is not in it.
     *
     * @param string $directory Absolute path as it was passed to {@see watch()}
     */
    public function unwatch(string $directory): void;

    /**
     * @return list<string> Absolute paths currently under watch
     */
    public function watched(): array;

    /**
     * Reports the watched directories that changed since the previous call, and forgets them.
     *
     * Every watched directory is returned when the engine lost events (an inotify queue
     * overflow), because "something happened and I cannot say where" and "everything
     * happened" are the same instruction to a consumer that answers by re-reading.
     *
     * @return list<string> Distinct watched directories that changed, in watch order
     */
    public function takeChanged(): array;

    /**
     * Forgets everything that accumulated so far, reporting nothing.
     *
     * Called immediately before a consumer re-reads the watched tree: what happened up to
     * this point is about to be observed by the read itself, and what happens after it is
     * still delivered.
     */
    public function discardPending(): void;

    /**
     * Releases the engine's resources; the instance stops reporting changes.
     */
    public function close(): void;
}
