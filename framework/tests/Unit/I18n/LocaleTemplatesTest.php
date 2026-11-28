<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\I18n;

use Hilos\Core\Exception\ValidationException;
use Hilos\Database\Entity\Item\Locale;
use Hilos\I18n\DTO\LocaleFormats;
use Hilos\I18n\LocaleTemplates;
use Hilos\I18n\MeasurementSystem;
use Hilos\I18n\Weekday;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The closed format catalog is safe for its columns and shared by the write door. */
final class LocaleTemplatesTest extends TestCase
{
    public function testTemplatesFitColumnsAndHaveNoDuplicates(): void
    {
        $templates = LocaleTemplates::toArray();
        self::assertSame([
            LocaleFormats::date, LocaleFormats::time, LocaleFormats::number, LocaleFormats::phone,
            LocaleFormats::address, LocaleFormats::measurement, LocaleFormats::collation, LocaleFormats::firstWeekday,
        ], array_keys($templates));
        self::assertSame([1, 2, 3, 4, 5, 6, 0], $templates[LocaleFormats::firstWeekday]);
        self::assertSame(array_map(
            static fn(MeasurementSystem $system): string => $system->value,
            MeasurementSystem::cases(),
        ), $templates[LocaleFormats::measurement]);
        foreach ([
            LocaleFormats::date => Locale::DATE_FORMAT_MAX_CHARS,
            LocaleFormats::time => Locale::TIME_FORMAT_MAX_CHARS,
            LocaleFormats::number => Locale::NUMBER_FORMAT_MAX_CHARS,
            LocaleFormats::phone => Locale::PHONE_FORMAT_MAX_CHARS,
            LocaleFormats::address => Locale::ADDRESS_FORMAT_MAX_CHARS,
            LocaleFormats::collation => Locale::COLLATION_MAX_CHARS,
        ] as $field => $maxChars) {
            self::assertSame($templates[$field], array_values(array_unique($templates[$field])));
            foreach ($templates[$field] as $template) {
                self::assertLessThanOrEqual($maxChars, mb_strlen($template), $field);
            }
        }
    }

    public function testAllKnownTemplatesPassTheWriteDoor(): void
    {
        foreach (LocaleTemplates::DATE as $date) {
            LocaleTemplates::refuseUnknown(self::formatsWith(LocaleFormats::date, $date));
        }
        $this->addToAssertionCount(count(LocaleTemplates::DATE));
    }

    public function testFirstsAreTheFirstTemplateOfEachListMetricAndMonday(): void
    {
        $firsts = LocaleTemplates::firsts();

        self::assertSame([
            LocaleFormats::date => LocaleTemplates::DATE[0],
            LocaleFormats::time => LocaleTemplates::TIME[0],
            LocaleFormats::number => LocaleTemplates::NUMBER[0],
            LocaleFormats::phone => LocaleTemplates::PHONE[0],
            LocaleFormats::address => LocaleTemplates::ADDRESS[0],
            LocaleFormats::measurement => MeasurementSystem::METRIC->value,
            LocaleFormats::collation => LocaleTemplates::COLLATION[0],
            LocaleFormats::firstWeekday => Weekday::MONDAY->value,
        ], $firsts->toArray());
        LocaleTemplates::refuseUnknown($firsts);
    }

    /** @return iterable<string, array{string, string, string}> Field, unknown value and expected label */
    public static function unknownTemplates(): iterable
    {
        yield 'date' => [LocaleFormats::date, '', 'Date format'];
        yield 'time' => [LocaleFormats::time, '12h', 'Time format'];
        yield 'number' => [LocaleFormats::number, '1.234', 'Number format'];
        yield 'phone' => [LocaleFormats::phone, '123', 'Phone format'];
        yield 'address' => [LocaleFormats::address, 'street', 'Address format'];
        yield 'sorting' => [LocaleFormats::collation, 'unicode', 'Sorting'];
    }

    /**
     * @param string $field Wire key of the invalid format
     * @param string $value Invalid format
     * @param string $label Expected field label
     */
    #[DataProvider('unknownTemplates')]
    public function testUnknownTemplateNamesFieldAndValue(string $field, string $value, string $label): void
    {
        $formats = self::formatsWith($field, $value);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("{$label} '{$value}' is not recognized: expected one of the known templates.");
        LocaleTemplates::refuseUnknown($formats);
    }

    /**
     * @param string $field Wire key of the one format to replace
     * @param string $value Its value
     * @return LocaleFormats The first templates with that one format replaced
     */
    private static function formatsWith(string $field, string $value): LocaleFormats
    {
        $data = LocaleTemplates::firsts()->toArray();
        $data[$field] = $value;

        return LocaleFormats::fromArray($data);
    }
}
