<?php

declare(strict_types=1);

namespace Hilos\DataExport;

use DateTimeImmutable;
use DateTimeZone;
use Hilos\Constants\TimeConstants;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * Moments in a person's export, in the one time zone chosen for that file: the stored moment is kept
 * and only its notation moves, with the offset written beside it.
 */
final class DataExportTime
{
    private const string ISO_SECONDS_FORMAT = 'Y-m-d\TH:i:sP';
    private const string ISO_MILLISECONDS_FORMAT = 'Y-m-d\TH:i:s.vP';
    private const string FILE_NAME_DATE_FORMAT = 'Y-m-d';

    /**
     * @param ?string $sqlDateTime Stored UTC date, or an absent optional date
     * @param DateTimeZone $timeZone Zone of the file being written
     * @return ?string ISO 8601 date with the zone offset, retaining absence
     */
    public static function iso(?string $sqlDateTime, DateTimeZone $timeZone): ?string
    {
        return $sqlDateTime === null
            ? null
            : self::moment(TimeHelper::sqlToMs($sqlDateTime), $timeZone)->format(self::ISO_SECONDS_FORMAT);
    }

    /**
     * @param int $milliseconds Moment in epoch milliseconds
     * @param DateTimeZone $timeZone Zone of the file being written
     * @return string ISO 8601 date with exactly the moment's milliseconds and the zone offset
     */
    public static function isoMs(int $milliseconds, DateTimeZone $timeZone): string
    {
        return self::moment($milliseconds, $timeZone)->format(self::ISO_MILLISECONDS_FORMAT);
    }

    /**
     * @param string $sqlDateTime Stored UTC date
     * @param DateTimeZone $timeZone Zone of the file being named
     * @return string Calendar date of the moment in that zone, for a file name
     */
    public static function date(string $sqlDateTime, DateTimeZone $timeZone): string
    {
        return self::moment(TimeHelper::sqlToMs($sqlDateTime), $timeZone)->format(self::FILE_NAME_DATE_FORMAT);
    }

    /**
     * @param int $milliseconds Moment in epoch milliseconds
     * @param DateTimeZone $timeZone Zone of the file being named
     * @return string Calendar date of the moment in that zone, for a file name
     */
    public static function dateMs(int $milliseconds, DateTimeZone $timeZone): string
    {
        return self::moment($milliseconds, $timeZone)->format(self::FILE_NAME_DATE_FORMAT);
    }

    /**
     * @param int $milliseconds Moment in epoch milliseconds, negative before 1970
     * @param DateTimeZone $timeZone Zone the moment is shown in
     * @return DateTimeImmutable The same moment, carried in that zone
     */
    private static function moment(int $milliseconds, DateTimeZone $timeZone): DateTimeImmutable
    {
        // Division truncates toward zero, so a moment before 1970 borrows one second to keep its milliseconds positive.
        $seconds = intdiv($milliseconds, TimeConstants::MS_PER_SECOND);
        $remainder = $milliseconds % TimeConstants::MS_PER_SECOND;
        if ($remainder < 0) {
            $seconds--;
            $remainder += TimeConstants::MS_PER_SECOND;
        }

        return DateTimeImmutable::createFromTimestamp($seconds)
            ->setMicrosecond($remainder * TimeConstants::US_PER_MILLISECOND)
            ->setTimezone($timeZone);
    }
}
