<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\I18n\DTO;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\I18n\DTO\LocaleFormats;
use Hilos\I18n\DTO\TabLanguage;
use PHPUnit\Framework\TestCase;

/** The tab's language keeps its wire shape, the formats nested, and refuses a broken payload. */
final class TabLanguageTest extends TestCase
{
    private const array FORMATS = [
        LocaleFormats::date => 'DD.MM.YYYY',
        LocaleFormats::time => 'HH:mm',
        LocaleFormats::number => '1 000,00',
        LocaleFormats::phone => '+X (XXX) XXX-XX-XX',
        LocaleFormats::address => 'Index, City, Street, House',
        LocaleFormats::measurement => 'metric',
        LocaleFormats::collation => 'und',
    ];

    private const array LANGUAGE = [
        TabLanguage::languageId => 7,
        TabLanguage::languageCode => 'ru',
        TabLanguage::rtl => false,
        TabLanguage::localeId => 12,
        TabLanguage::localeCode => 'ru',
        TabLanguage::formats => self::FORMATS,
    ];

    public function testRoundTrip(): void
    {
        self::assertSame(self::LANGUAGE, TabLanguage::fromArray(self::LANGUAGE)->toArray());
    }

    public function testRoundTripWithoutStoredLocale(): void
    {
        $data = self::LANGUAGE;
        $data[TabLanguage::localeId] = null;
        $data[TabLanguage::localeCode] = null;
        $language = TabLanguage::fromArray($data);

        self::assertNull($language->localeId);
        self::assertNull($language->localeCode);
        self::assertSame($data, $language->toArray());
    }

    public function testRejectsMissingField(): void
    {
        $data = self::LANGUAGE;
        unset($data[TabLanguage::languageCode]);
        $this->expectException(InvalidFormatException::class);
        TabLanguage::fromArray($data);
    }

    public function testRejectsMissingFormats(): void
    {
        $data = self::LANGUAGE;
        unset($data[TabLanguage::formats]);
        $this->expectException(InvalidFormatException::class);
        TabLanguage::fromArray($data);
    }

    public function testRejectsUnknownUnits(): void
    {
        $data = self::LANGUAGE;
        $data[TabLanguage::formats][LocaleFormats::measurement] = 'unknown';
        $this->expectException(InvalidFormatException::class);
        TabLanguage::fromArray($data);
    }
}
