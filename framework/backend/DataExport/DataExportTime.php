<?php

declare(strict_types=1);

namespace Hilos\DataExport;

use Hilos\Constants\TimeConstants;
use Hilos\Utils\Helpers\TimeHelper;

/** Dates in portable archives use UTC independently of the browser's time zone. */
final class DataExportTime
{
    /**
     * @param ?string $sqlDateTime Stored date, or an absent optional date
     * @return ?string ISO 8601 UTC date, retaining absence
     */
    public static function iso(?string $sqlDateTime): ?string
    {
        return $sqlDateTime === null ? null : gmdate(
            'Y-m-d\TH:i:s\Z',
            intdiv(TimeHelper::sqlToMs($sqlDateTime), TimeConstants::MS_PER_SECOND),
        );
    }
}
