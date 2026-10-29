<?php

declare(strict_types=1);

namespace Hilos\Status;

/** Aggregate of future unfinished announcements without loading their rows. */
final readonly class StatusWorksSummaryData
{
    /**
     * @param int $upcoming Number of future unfinished announcements
     * @param ?int $nextStartsAt Nearest future start in UTC epoch milliseconds, null when none
     */
    public function __construct(public int $upcoming, public ?int $nextStartsAt)
    {
    }
}
