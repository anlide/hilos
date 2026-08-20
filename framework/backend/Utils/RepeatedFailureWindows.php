<?php

declare(strict_types=1);

namespace Hilos\Utils;

/**
 * RepeatedFailureWindows - how often the same failure is allowed into the journal.
 *
 * A process that contains failures instead of dying gets one new problem for free: a
 * cause that does not go away writes its line on every tick, and a journal filled with
 * one repeated sentence is as unreadable as one with nothing in it. The cure both
 * containing processes settled on is the same - the first few failures of a kind are
 * written in full, the rest of that window are counted, and when the window closes it
 * says how many it held back - so the counting lives here and each process keeps its
 * own words.
 *
 * This class writes nothing and knows no wording: it is handed a key, it answers whether
 * that key is still being written in full, and when a window ends it hands back what the
 * window swallowed. The sentence around that number belongs to whoever owns the instance,
 * because the master's readers and the worker's tick describe the same arithmetic to
 * different readers.
 *
 * The thresholds are constructor arguments and not constants: the master and the worker
 * happen to hold the same two numbers today, and that is a coincidence of two independent
 * judgements about two different journals, not one quantity written twice.
 *
 * Instances, not statics: a static count is one count per process, and the two owners
 * would then share the burst allowance they are supposed to spend separately.
 *
 * Nothing counted is lost. A window whose time ran out while its key kept failing is put
 * aside rather than dropped, and the next {@see self::closeExpired()} hands it over with
 * the rest - so an owner that only sweeps from its loop still learns what was held back,
 * whatever order it calls these two methods in.
 */
class RepeatedFailureWindows
{
    /** @var int Number of failures per key written in full before a window starts counting instead */
    private int $burstLines;

    /** @var float Length of the window a key's failures are counted over, in seconds */
    private float $windowSeconds;

    /**
     * Open windows by key, each holding when it opened and what it has seen.
     *
     * @var array<string, array{openedAt: float, written: int, held: int}>
     */
    private array $windows = [];

    /**
     * Windows a new failure of the same key replaced, waiting to be handed over.
     *
     * @var list<array{key: string, held: int}>
     */
    private array $replaced = [];

    /**
     * @param int $burstLines Number of failures per key written in full before counting starts
     * @param float $windowSeconds Length of the window a key's failures are counted over, in seconds
     */
    public function __construct(int $burstLines, float $windowSeconds)
    {
        $this->burstLines = $burstLines;
        $this->windowSeconds = $windowSeconds;
    }

    /**
     * Tells whether this failure is still written in full, and counts it either way.
     *
     * A window belongs to one key, so a flood under one key cannot silence what is
     * failing under another. What goes into the key is the owner's decision - it is the
     * answer to "which failures are the same failure repeating".
     *
     * @param string $key What this failure counts as, for the limit
     * @param float $now Current time, as the caller reads it
     * @return bool True when the line is written rather than held back
     */
    public function admits(string $key, float $now): bool
    {
        $window = $this->windows[$key] ?? null;

        if ($window === null || $now - $window['openedAt'] >= $this->windowSeconds) {
            if ($window !== null) {
                $this->replaced[] = ['key' => $key, 'held' => $window['held']];
            }

            $this->windows[$key] = ['openedAt' => $now, 'written' => 1, 'held' => 0];

            return true;
        }

        if ($window['written'] < $this->burstLines) {
            $this->windows[$key]['written']++;

            return true;
        }

        $this->windows[$key]['held']++;

        return false;
    }

    /**
     * Hands over every window whose length has run out, and forgets it.
     *
     * Meant to be called from the owner's loop rather than from the next failure of the
     * same kind: a stream that stopped would otherwise leave its tail uncounted until
     * something of that exact key failed again, which for a cause that has been fixed is
     * never.
     *
     * @param float $now Current time, as the caller reads it
     * @return list<array{key: string, held: int}> Closed windows, each with what it held back
     */
    public function closeExpired(float $now): array
    {
        $closed = $this->replaced;
        $this->replaced = [];

        foreach ($this->windows as $key => $window) {
            if ($now - $window['openedAt'] < $this->windowSeconds) {
                continue;
            }

            $closed[] = ['key' => $key, 'held' => $window['held']];
            unset($this->windows[$key]);
        }

        return $closed;
    }

    /**
     * Forgets every window without handing it over.
     *
     * For a test that needs the next case to start counting from nothing; a process has
     * no reason to drop what it has not reported yet.
     */
    public function reset(): void
    {
        $this->windows = [];
        $this->replaced = [];
    }
}
