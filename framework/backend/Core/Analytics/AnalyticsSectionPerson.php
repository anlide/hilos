<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics;

/** One numeric account's grouped facts from the selected browser sessions. */
final readonly class AnalyticsSectionPerson
{
    /**
     * @param int $userId Last signed-in account number
     * @param int $sessionCount Exact number of sessions in the selected period
     * @param int $lastSeenTs Latest source moment in UTC epoch milliseconds, possibly beyond the read's upper bound
     */
    public function __construct(public int $userId, public int $sessionCount, public int $lastSeenTs)
    {
    }
}
