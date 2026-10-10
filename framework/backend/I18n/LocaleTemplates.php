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

    /** @return array<string, list<string>> Templates for each format field */
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
        ];
    }

    /**
     * @param string $date Date template
     * @param string $time Time template
     * @param string $number Number template
     * @param string $phone Phone template
     * @param string $address Address template
     * @param string $collation Sorting template
     * @throws ValidationException When the first unknown template is found
     */
    public static function refuseUnknown(
        string $date,
        string $time,
        string $number,
        string $phone,
        string $address,
        string $collation,
    ): void {
        foreach ([
            'Date format' => [$date, self::DATE],
            'Time format' => [$time, self::TIME],
            'Number format' => [$number, self::NUMBER],
            'Phone format' => [$phone, self::PHONE],
            'Address format' => [$address, self::ADDRESS],
            'Sorting' => [$collation, self::COLLATION],
        ] as $label => [$value, $templates]) {
            if (!in_array($value, $templates, true)) {
                throw new ValidationException("{$label} '{$value}' is not recognized: expected one of the known templates.");
            }
        }
    }
}
