<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics;

/** Three exact counts from one SQL snapshot and the context they describe. */
final readonly class AnalyticsOverviewSnapshot
{
    public const string SECTION = 'analyticsOverview';
    public const string TIME_ZONE = 'timeZone';
    public const string WEEK_START_TS = 'weekStartTs';
    public const string DAY_START_TS = 'dayStartTs';
    public const string HOUR_START_TS = 'hourStartTs';
    public const string AS_OF_TS = 'asOfTs';
    public const string VISITORS_THIS_WEEK = 'visitorsThisWeek';
    public const string VISITORS_TODAY = 'visitorsToday';
    public const string VISITORS_THIS_HOUR = 'visitorsThisHour';

    /**
     * @param string $timeZone Reader's captured IANA zone
     * @param int $weekStartTs Week start in UTC epoch milliseconds
     * @param int $dayStartTs Day start in UTC epoch milliseconds
     * @param int $hourStartTs Hour start in UTC epoch milliseconds
     * @param int $asOfTs Common closed upper bound in UTC epoch milliseconds
     * @param int $visitorsThisWeek Sessions intersecting the week
     * @param int $visitorsToday Sessions intersecting the day
     * @param int $visitorsThisHour Sessions intersecting the hour
     */
    public function __construct(
        public string $timeZone,
        public int $weekStartTs,
        public int $dayStartTs,
        public int $hourStartTs,
        public int $asOfTs,
        public int $visitorsThisWeek,
        public int $visitorsToday,
        public int $visitorsThisHour,
    ) {
    }

    /** @return array<string, int|string> Complete overview wire object */
    public function toArray(): array
    {
        return [
            self::TIME_ZONE => $this->timeZone,
            self::WEEK_START_TS => $this->weekStartTs,
            self::DAY_START_TS => $this->dayStartTs,
            self::HOUR_START_TS => $this->hourStartTs,
            self::AS_OF_TS => $this->asOfTs,
            self::VISITORS_THIS_WEEK => $this->visitorsThisWeek,
            self::VISITORS_TODAY => $this->visitorsToday,
            self::VISITORS_THIS_HOUR => $this->visitorsThisHour,
        ];
    }
}
