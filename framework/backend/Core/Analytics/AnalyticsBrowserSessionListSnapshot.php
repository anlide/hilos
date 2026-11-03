<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics;

/** Membership, bounded total and position of one browser-session window. */
final readonly class AnalyticsBrowserSessionListSnapshot
{
    /**
     * @param list<AnalyticsSectionBrowserSession> $sessions Selected sessions in canonical order
     * @param int $totalCount Matching sessions, capped at the table ceiling
     * @param bool $totalExact Whether the count reached the end of the set
     * @param ?int $rowsBefore Position of the window, absent when the total overflowed
     */
    public function __construct(
        public array $sessions,
        public int $totalCount,
        public bool $totalExact,
        public ?int $rowsBefore,
    ) {
    }
}
