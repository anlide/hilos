<?php

declare(strict_types=1);

namespace Hilos\Pages\Profile;

use DateTimeZone;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Page\DTO\PagePayload;

/** Projects the process tzdb catalog into the profile's scalar list snapshot. */
final class HilosProfileTimeZones
{
    public const string LIST = 'profileTimeZones';
    public const string SLOT = 'timeZone';

    /** @return array{cleared: true, items: list<array{itemKey: string, slots: array{timeZone: string}}>} Full sorted catalog snapshot */
    public static function toArray(): array
    {
        $timeZones = array_unique([...DateTimeZone::listIdentifiers(DateTimeZone::ALL), TimeConstants::UTC_TIME_ZONE]);
        sort($timeZones, SORT_STRING);
        $items = [];
        foreach ($timeZones as $timeZone) {
            $items[] = [
                PagePayload::itemKey => $timeZone,
                PagePayload::slots => [self::SLOT => $timeZone],
            ];
        }

        return [PagePayload::cleared => true, PagePayload::items => $items];
    }
}
