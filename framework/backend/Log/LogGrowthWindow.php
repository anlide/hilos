<?php

declare(strict_types=1);

namespace Hilos\Log;

/**
 * Rolling day window measuring how much one log key grew (HIL-753).
 *
 * One window per key, held in {@see LogStoreAgent}'s memory. It measures continuously rather than
 * while somebody watches a page: without an unobserved measurement the day figure could never
 * exist, and a key that vanished between two visits would go unnoticed.
 *
 * Growth is accumulated into a monotonic counter: every sample adds the increase of the key's total
 * weight — live file plus every batch of it — and never a decrease, so a rotation or a cleaned-away
 * batch contributes zero rather than a negative number. The counter is stamped into a point every
 * {@see self::SAMPLE_INTERVAL_SECONDS}, and the day figure is the counter now minus the counter at
 * the oldest point still a full day back. A day at one-minute resolution would be 1440 numbers per
 * key for nothing; quarter-hour steps make it 97.
 *
 * Before a day has passed there is no honest number, and the window says so with null — the column
 * shows a dash. The same holds after a restart and after the store went unreadable
 * ({@see reset()}): for the time nobody could read the directory we do not know what was written
 * into it, and filling the gap from the archive would mix two sources in one column.
 */
final class LogGrowthWindow
{
    /** Seconds between two stamped points; a day of them is {@see self::POINT_CAPACITY}. */
    private const int SAMPLE_INTERVAL_SECONDS = 900;

    /** Age a point must reach before it can answer for "a day ago". */
    private const int WINDOW_SECONDS = 86400;

    /** Points kept: the 96 quarter-hours of a day plus the one that closes it. */
    private const int POINT_CAPACITY = 97;

    /** @var int Monotonic count of bytes this key has gained since the window opened */
    private int $counter = 0;

    /** @var ?int Total weight at the previous sample, or null before the first one */
    private ?int $previousTotalBytes = null;

    /** @var list<array{int, int}> Ring of (Unix timestamp, counter) points, oldest first */
    private array $points = [];

    /**
     * Take one sample of the key's total weight.
     *
     * @param int $now Unix timestamp of the sample
     * @param int $totalBytes Total weight of the key right now: live file plus every batch of it
     */
    public function addSample(int $now, int $totalBytes): void
    {
        if ($this->previousTotalBytes !== null) {
            $this->counter += max(0, $totalBytes - $this->previousTotalBytes);
        }
        $this->previousTotalBytes = $totalBytes;

        $newestPointAt = $this->points === [] ? null : $this->points[count($this->points) - 1][0];
        if ($newestPointAt !== null && $now - $newestPointAt < self::SAMPLE_INTERVAL_SECONDS) {
            return;
        }

        $this->points[] = [$now, $this->counter];
        if (count($this->points) > self::POINT_CAPACITY) {
            array_shift($this->points);
        }
    }

    /**
     * Bytes this key gained over the last day.
     *
     * @param int $now Unix timestamp to measure the day back from
     *
     * @return ?int Bytes gained, or null while the window is still shorter than a day
     */
    public function growthPerDay(int $now): ?int
    {
        // Newest first, so the point answering for "a day ago" is the closest one that is old enough.
        for ($i = count($this->points) - 1; $i >= 0; $i--) {
            [$at, $counter] = $this->points[$i];
            if ($now - $at >= self::WINDOW_SECONDS) {
                return $this->counter - $counter;
            }
        }

        return null;
    }

    /**
     * Break the series, so the next day figure is a dash rather than a guess.
     *
     * Called when the store goes unreadable: whatever was written while nobody could look is not
     * measurable afterwards, and carrying the old counter across the gap would report it as growth
     * that happened in the last day.
     */
    public function reset(): void
    {
        $this->counter = 0;
        $this->previousTotalBytes = null;
        $this->points = [];
    }
}
