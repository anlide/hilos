<?php

declare(strict_types=1);

namespace Hilos\Pages\Analytics\DTO;

use Hilos\Auth\Session\BrowserTimeZone;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Core\Page\AbstractPageSubscribeParamsDTO;
use Hilos\Core\Page\Exception\InvalidPageRouteParamException;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\PageRouteParams;

/** One opening's reader zone and ordered calendar starts, without domain lookups. */
final class HilosAnalyticsOverviewPageSubscribeParams extends AbstractPageSubscribeParamsDTO
{
    private const int MAX_DATE_TS_MS = 8640000000000000;

    /**
     * @param string $timeZone Reader's captured IANA zone
     * @param int $weekStartTs Week start in UTC epoch milliseconds
     * @param int $dayStartTs Day start in UTC epoch milliseconds
     * @param int $hourStartTs Hour start in UTC epoch milliseconds
     */
    public function __construct(
        public readonly string $timeZone,
        public readonly int $weekStartTs,
        public readonly int $dayStartTs,
        public readonly int $hourStartTs,
    ) {
    }

    /**
     * @param PageRouteParams $params Raw subscription parameters
     * @return static Validated reader context
     * @throws MissingPageRouteParamException When a required context value is absent or empty
     * @throws InvalidPageRouteParamException When the zone or ordered periods are invalid
     */
    public static function fromPageRouteParams(PageRouteParams $params): static
    {
        $timeZone = $params->requireString(HilosPageRouteParams::HILOS_ANALYTICS_TIME_ZONE);
        if (!BrowserTimeZone::isValid($timeZone)) {
            throw new InvalidPageRouteParamException(
                HilosPageRouteParams::HILOS_ANALYTICS_TIME_ZONE,
                'Choose a valid time zone for the analytics period.',
            );
        }
        try {
            $weekStartTs = $params->requireInt(HilosPageRouteParams::HILOS_ANALYTICS_WEEK_START_TS);
            $dayStartTs = $params->requireInt(HilosPageRouteParams::HILOS_ANALYTICS_DAY_START_TS);
            $hourStartTs = $params->requireInt(HilosPageRouteParams::HILOS_ANALYTICS_HOUR_START_TS);
        } catch (InvalidPageRouteParamException $exception) {
            throw new InvalidPageRouteParamException(
                $exception->paramKey,
                'Choose a valid analytics period with whole millisecond starts.',
                $exception,
            );
        }
        if ($weekStartTs < 0 || $weekStartTs > $dayStartTs || $dayStartTs > $hourStartTs || $hourStartTs > self::MAX_DATE_TS_MS) {
            throw new InvalidPageRouteParamException(
                HilosPageRouteParams::HILOS_ANALYTICS_HOUR_START_TS,
                'Choose a valid analytics period with ordered calendar starts.',
            );
        }

        return new static($timeZone, $weekStartTs, $dayStartTs, $hourStartTs);
    }
}
