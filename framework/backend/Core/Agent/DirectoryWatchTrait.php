<?php

declare(strict_types=1);

namespace Hilos\Core\Agent;

use Hilos\Fs\Exception\DirectoryWatchException;
use Hilos\Fs\Watch\FsRescanSchedule;
use Hilos\Fs\Watch\FsWatch;
use Hilos\Fs\Watch\FsWatchInterface;

/**
 * Lets an agent notice that a directory it owns changed, without being told by the writer.
 *
 * The seam between {@see FsWatch} and an agent that mirrors a directory tree into runtime
 * state. It is four calls because the ordering around a re-read is the part that is easy to
 * get wrong, and it is a trait rather than a base class for the reason
 * {@see ProtectedModeOperatorTrait} is one: its carriers share no ancestor but
 * {@see AbstractAgent}, and only the agent that owns the tree may watch it.
 *
 * The carrier wires it in four places:
 * - `onStart()` - {@see watchDirectories()} BEFORE its first read of the tree;
 * - `onTick()` - `if ({@see directoryRescanDue()}) { ...re-read... }`;
 * - the re-read itself - {@see discardDirectoryChanges()} as its FIRST statement, and
 *   {@see watchDirectories()} as its last;
 * - `onStop()` - {@see closeDirectoryWatch()}.
 *
 * **Why the watch is taken before the first read, and dropped before every later one.** A
 * read that runs before the watch exists loses whatever lands between them. A read that runs
 * without dropping what accumulated re-reads the tree a second time for its own writes. Both
 * orderings are exact rather than approximate: a foreign write landing before the drop is
 * seen by the read itself, and one landing after it is still queued and wakes the next tick,
 * so no ordering can lose a change and the worst case is one redundant read.
 *
 * **Why reconciliation happens after every read rather than once at start.** The directories
 * a consumer owns are state, not a fixed list: a scope directory is created the first time
 * something is written into it and can be removed by hand afterwards. Passing the current
 * set on every read keeps the watch in step with what is actually on disk.
 *
 * **No event-loop change.** An agent lives in a worker whose loop already targets its own
 * tick budget, so asking a drained queue once per tick bounds the latency at one tick
 * without anything here waiting on a descriptor.
 */
trait DirectoryWatchTrait
{
    /** @var ?FsWatchInterface Open watch, or null before the first {@see watchDirectories()} */
    private ?FsWatchInterface $directoryWatch = null;

    /** @var ?FsRescanSchedule Policy deciding when a re-read is due, alive with the watch */
    private ?FsRescanSchedule $directoryRescanSchedule = null;

    /**
     * Takes exactly these directories under watch, opening the watch on the first call.
     *
     * Idempotent and total: a directory already watched stays as it is, one that is new is
     * added, and one that is no longer in the set is dropped - so the caller passes what it
     * owns right now rather than a delta. A directory that refuses to be watched costs a
     * warning and nothing else; the next call tries it again, which is what makes a scope
     * directory that appears later work by itself.
     *
     * @param list<string> $directories Absolute paths that should be under watch
     */
    protected function watchDirectories(array $directories): void
    {
        $watch = $this->directoryWatch;
        if ($watch === null) {
            $watch = FsWatch::open();
            $this->directoryWatch = $watch;
            $this->directoryRescanSchedule = new FsRescanSchedule(microtime(true));
        }

        foreach (array_diff($watch->watched(), $directories) as $gone) {
            $watch->unwatch($gone);
        }

        foreach ($directories as $directory) {
            try {
                $watch->watch($directory);
            } catch (DirectoryWatchException $e) {
                $this->logAgentWarning("Directory watch not taken: {$e->getMessage()}");
            }
        }
    }

    /**
     * Answers whether the carrier should re-read its tree on this tick.
     *
     * Drains the watch on every call whatever the answer is: what it reported is remembered
     * by the schedule, so a change is never lost by being asked about too early.
     *
     * @return bool True when a re-read is due, false when no watch is open
     */
    protected function directoryRescanDue(): bool
    {
        $watch = $this->directoryWatch;
        $schedule = $this->directoryRescanSchedule;
        if ($watch === null || $schedule === null) {
            return false;
        }

        $now = microtime(true);
        if ($watch->takeChanged() !== []) {
            $schedule->noteChanges($now);
        }

        return $schedule->isDue($now);
    }

    /**
     * Drops what the watch accumulated and starts the period again, as a re-read begins.
     *
     * The first statement of the carrier's re-read, so that the read counts as the answer to
     * everything reported up to this point - including the carrier's own writes, which is
     * what keeps them from waking the very next tick.
     */
    protected function discardDirectoryChanges(): void
    {
        $watch = $this->directoryWatch;
        $schedule = $this->directoryRescanSchedule;
        if ($watch === null || $schedule === null) {
            return;
        }

        $watch->discardPending();
        $schedule->noteScan(microtime(true));
    }

    /**
     * Releases the watch on shutdown; a later {@see watchDirectories()} would open a new one.
     */
    protected function closeDirectoryWatch(): void
    {
        $this->directoryWatch?->close();

        $this->directoryWatch = null;
        $this->directoryRescanSchedule = null;
    }
}
