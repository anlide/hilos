<?php

declare(strict_types=1);

namespace Hilos\Tables\ChangeLog;

use DateTimeImmutable;
use DateInterval;

/** The lower bound of a journal table's optional period filter. */
enum HilosChangeLogPeriod: string
{
    case HOUR = 'hour';
    case DAY = 'day';
    case WEEK = 'week';
    case MONTH = 'month';

    /** @return DateTimeImmutable Inclusive lower bound in UTC */
    public function since(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->sub(match ($this) {
            self::HOUR => new DateInterval('PT1H'),
            self::DAY => new DateInterval('P1D'),
            self::WEEK => new DateInterval('P7D'),
            self::MONTH => new DateInterval('P30D'),
        });
    }
}
