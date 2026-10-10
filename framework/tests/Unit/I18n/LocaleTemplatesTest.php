<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\I18n;

use Hilos\Core\Exception\ValidationException;
use Hilos\Database\Entity\Item\Locale;
use Hilos\I18n\DTO\LocaleFormats;
use Hilos\I18n\LocaleTemplates;
use Hilos\I18n\MeasurementSystem;
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
            LocaleFormats::address, LocaleFormats::measurement, LocaleFormats::collation,
        ], array_keys($templates));
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
            LocaleTemplates::refuseUnknown(
                $date, LocaleTemplates::TIME[0], LocaleTemplates::NUMBER[0],
                LocaleTemplates::PHONE[0], LocaleTemplates::ADDRESS[0], LocaleTemplates::COLLATION[0],
            );
        }
        $this->addToAssertionCount(count(LocaleTemplates::DATE));
    }

    /** @return iterable<string, array{int, string, string}> Unknown values and expected labels */
    public static function unknownTemplates(): iterable
    {
        yield 'date' => [0, '', 'Date format'];
        yield 'time' => [1, '12h', 'Time format'];
        yield 'number' => [2, '1.234', 'Number format'];
        yield 'phone' => [3, '123', 'Phone format'];
        yield 'address' => [4, 'street', 'Address format'];
        yield 'sorting' => [5, 'unicode', 'Sorting'];
    }

    /**
     * @param int $index Index of the invalid format
     * @param string $value Invalid format
     * @param string $label Expected field label
     */
    #[DataProvider('unknownTemplates')]
    public function testUnknownTemplateNamesFieldAndValue(int $index, string $value, string $label): void
    {
        $values = [
            LocaleTemplates::DATE[0], LocaleTemplates::TIME[0], LocaleTemplates::NUMBER[0],
            LocaleTemplates::PHONE[0], LocaleTemplates::ADDRESS[0], LocaleTemplates::COLLATION[0],
        ];
        $values[$index] = $value;
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("{$label} '{$value}' is not recognized: expected one of the known templates.");
        LocaleTemplates::refuseUnknown(...$values);
    }
}
