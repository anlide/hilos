<?php

declare(strict_types=1);

namespace Hilos\I18n;

use Hilos\Core\Exception\ValidationException;
use Hilos\I18n\DTO\LocaleFormats;

/** Closed locale display templates from hleb/main/Object/Locale.php (D-199). */
final class LocaleTemplates
{
    public const array DATE = ['DD.MM.YYYY', 'DD/MM/YYYY', 'DD-MM-YYYY', 'YYYY.MM.DD', 'YYYY/MM/DD', 'YYYY-MM-DD', 'MM/DD/YYYY'];
    public const array TIME = ['HH:mm:ss', 'hh:mm:ss a'];
    public const array NUMBER = ['1,000.00', '1 000,00', '1 000.00', '1.000,00'];
    public const array PHONE = [
        '+X (XXX) XXX-XXXX', '+XX (X) XXXX XXXX', '+XX-XXXX-XXXX',
        '+XX XX XXXX XXXX', '+XX-XXXXX-XXXXX', '+XXX X XXX XXXX',
    ];
    public const array ADDRESS = [
        'House, Street, State, Index', 'Street, House, Index, City',
        'Index, Prefecture, City, Region, House', 'Index, Province, City, Region, Street, House',
        'House, Street, Region, City, State, Index', 'Street, House, City, Index',
    ];
    public const array COLLATION = ['und'];

    /** @return array<string, list<string>|list<int>> Templates for each format field; first weekdays Monday to Sunday */
    public static function toArray(): array
    {
        return [
            LocaleFormats::date => self::DATE,
            LocaleFormats::time => self::TIME,
            LocaleFormats::number => self::NUMBER,
            LocaleFormats::phone => self::PHONE,
            LocaleFormats::address => self::ADDRESS,
            LocaleFormats::measurement => array_map(
                static fn(MeasurementSystem $system): string => $system->value,
                MeasurementSystem::cases(),
            ),
            LocaleFormats::collation => self::COLLATION,
            LocaleFormats::firstWeekday => [
                Weekday::MONDAY->value,
                Weekday::TUESDAY->value,
                Weekday::WEDNESDAY->value,
                Weekday::THURSDAY->value,
                Weekday::FRIDAY->value,
                Weekday::SATURDAY->value,
                Weekday::SUNDAY->value,
            ],
        ];
    }

    /** @return LocaleFormats The first template of each list, the metric system and Monday */
    public static function firsts(): LocaleFormats
    {
        return new LocaleFormats(
            self::DATE[0],
            self::TIME[0],
            self::NUMBER[0],
            self::PHONE[0],
            self::ADDRESS[0],
            MeasurementSystem::METRIC,
            self::COLLATION[0],
            Weekday::MONDAY,
        );
    }

    /**
     * The unit system and the first weekday are not checked here: their types admit only known values.
     *
     * @param LocaleFormats $formats Display values to check
     * @throws ValidationException When the first unknown template is found
     */
    public static function refuseUnknown(LocaleFormats $formats): void
    {
        foreach ([
            'Date format' => [$formats->date, self::DATE],
            'Time format' => [$formats->time, self::TIME],
            'Number format' => [$formats->number, self::NUMBER],
            'Phone format' => [$formats->phone, self::PHONE],
            'Address format' => [$formats->address, self::ADDRESS],
            'Sorting' => [$formats->collation, self::COLLATION],
        ] as $label => [$value, $templates]) {
            if (!in_array($value, $templates, true)) {
                throw new ValidationException("{$label} '{$value}' is not recognized: expected one of the known templates.");
            }
        }
    }
}
