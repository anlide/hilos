<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\I18n\Catalog;

use Hilos\I18n\Catalog\BuiltInI18nCatalog;
use Hilos\I18n\Catalog\CountryDefinition;
use Hilos\I18n\Catalog\CountryNameDefinition;
use Hilos\I18n\Catalog\DefaultLocaleDefinition;
use Hilos\I18n\Catalog\LanguageDefinition;
use Hilos\I18n\Catalog\LocaleDefinition;
use PHPUnit\Framework\TestCase;

/** Pins the content and typed read boundary of the framework's built-in catalog. */
final class BuiltInI18nCatalogTest extends TestCase
{
    public function testTheShippedCatalogHasItsPinnedContentDigest(): void
    {
        self::assertSame(
            '326cd5831e69e3d724b81e17d03d54ece3ac3de8a82b097160cbb04e96bbcdca',
            BuiltInI18nCatalog::fingerprint(),
        );
    }

    public function testLanguagesAndCountries(): void
    {
        $languages = iterator_to_array(BuiltInI18nCatalog::languages());
        $countries = iterator_to_array(BuiltInI18nCatalog::countries());

        self::assertCount(50, $languages);
        self::assertCount(51, $countries);
        self::assertEquals(new LanguageDefinition('en', 'English', false), BuiltInI18nCatalog::language('en'));
        self::assertEquals(new LanguageDefinition('ar', 'العربية', true), BuiltInI18nCatalog::language('ar'));
        self::assertEquals(new CountryDefinition('us', '$', 'USD'), BuiltInI18nCatalog::country('us'));
        self::assertEquals(new CountryDefinition('de', '€', 'EUR'), BuiltInI18nCatalog::country('de'));
        self::assertNull(BuiltInI18nCatalog::language('EN'));
        self::assertNull(BuiltInI18nCatalog::country('US'));
        self::assertNull(BuiltInI18nCatalog::language('missing'));
        self::assertNull(BuiltInI18nCatalog::country('missing'));

        foreach ($languages as $language) {
            self::assertInstanceOf(LanguageDefinition::class, $language);
            self::assertEquals($language, BuiltInI18nCatalog::language($language->code));
            self::assertSame(1, preg_match('/^[a-z]{2,3}$/D', $language->code));
            self::assertNotSame('', $language->nativeName);
        }
        foreach ($countries as $country) {
            self::assertInstanceOf(CountryDefinition::class, $country);
            self::assertEquals($country, BuiltInI18nCatalog::country($country->code));
            self::assertSame(1, preg_match('/^[a-z]{2}$/D', $country->code));
            self::assertNotSame('', $country->currencySymbol);
            self::assertSame(1, preg_match('/^[A-Z]{3}$/D', $country->currencyCode));
        }
    }

    public function testLocalesCarryCanonicalCodesAndSevenFormats(): void
    {
        $locales = iterator_to_array(BuiltInI18nCatalog::locales());
        self::assertCount(128, $locales);
        self::assertEquals(
            new LocaleDefinition(
                'en-US', 'en', 'us', 'MM/DD/YYYY', 'hh:mm:ss a', '1,000.00',
                '+X (XXX) XXX-XXXX', 'House, Street, State, Index', 'imperial', 'und',
            ),
            BuiltInI18nCatalog::locale('en-US'),
        );
        self::assertNull(BuiltInI18nCatalog::locale('en_US'));
        self::assertNull(BuiltInI18nCatalog::locale('EN-US'));
        self::assertNull(BuiltInI18nCatalog::locale('missing'));

        $countryless = 0;
        foreach ($locales as $locale) {
            self::assertInstanceOf(LocaleDefinition::class, $locale);
            self::assertEquals($locale, BuiltInI18nCatalog::locale($locale->code));
            self::assertSame(
                $locale->languageCode . ($locale->countryCode === null ? '' : '-' . strtoupper($locale->countryCode)),
                $locale->code,
            );
            self::assertNotNull(BuiltInI18nCatalog::language($locale->languageCode));
            self::assertContains($locale->measurementSystem, ['metric', 'imperial']);
            foreach ([
                $locale->dateFormat, $locale->timeFormat, $locale->numberFormat,
                $locale->phoneFormat, $locale->addressFormat,
                $locale->measurementSystem, $locale->collation,
            ] as $format) {
                self::assertNotSame('', $format);
            }
            self::assertSame('und', $locale->collation);
            $countryless += (int)($locale->countryCode === null);
        }
        self::assertSame(8, $countryless);
    }

    public function testDefaultLocalesPreserveSourceHintsEvenWhenRowsAreAbsent(): void
    {
        $defaults = iterator_to_array(BuiltInI18nCatalog::defaultLocales());
        self::assertCount(87, $defaults);
        self::assertSame('ms-MY', BuiltInI18nCatalog::defaultLocale('my'));
        self::assertNull(BuiltInI18nCatalog::locale('ms-MY'));
        self::assertNull(BuiltInI18nCatalog::defaultLocale('MY'));
        self::assertNull(BuiltInI18nCatalog::defaultLocale('missing'));

        $knownCountries = 0;
        $missingLocales = 0;
        foreach ($defaults as $default) {
            self::assertInstanceOf(DefaultLocaleDefinition::class, $default);
            self::assertSame($default->localeCode, BuiltInI18nCatalog::defaultLocale($default->countryCode));
            self::assertSame(1, preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/D', $default->localeCode));
            $knownCountries += (int)(BuiltInI18nCatalog::country($default->countryCode) !== null);
            $missingLocales += (int)(BuiltInI18nCatalog::locale($default->localeCode) === null);
        }
        self::assertSame(49, $knownCountries);
        self::assertSame(38, count($defaults) - $knownCountries);
        self::assertSame(21, $missingLocales);
    }

    public function testCountryNamesCoverEveryCountryLanguagePair(): void
    {
        $names = iterator_to_array(BuiltInI18nCatalog::countryNames());
        self::assertCount(2550, $names);
        self::assertEquals(new CountryNameDefinition('us', 'en', 'United States'), $names[0]);
        self::assertSame('United States', BuiltInI18nCatalog::countryName('us', 'en'));
        self::assertNull(BuiltInI18nCatalog::countryName('US', 'en'));
        self::assertNull(BuiltInI18nCatalog::countryName('us', 'EN'));
        self::assertNull(BuiltInI18nCatalog::countryName('missing', 'en'));

        foreach (BuiltInI18nCatalog::countries() as $country) {
            foreach (BuiltInI18nCatalog::languages() as $language) {
                self::assertNotSame('', BuiltInI18nCatalog::countryName($country->code, $language->code));
            }
        }
        foreach ($names as $name) {
            self::assertInstanceOf(CountryNameDefinition::class, $name);
            self::assertSame($name->name, BuiltInI18nCatalog::countryName($name->countryCode, $name->languageCode));
        }
    }
}
