<?php

declare(strict_types=1);

namespace Hilos\I18n;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\View\Collection\ItemNotFoundException;
use Hilos\Database\Object\Item\Locale as ObjectLocale;
use Hilos\Database\View\Collection\Locales;
use Hilos\Database\View\Item\Country;
use Hilos\Database\View\Item\Language;
use Hilos\Database\View\Item\Locale;
use Hilos\Environment\Exception\EnvException;
use Hilos\Environment\Exception\EnvInvalidValueException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\I18n\Catalog\BuiltInI18nCatalog;
use Hilos\I18n\DTO\LocaleFormats;
use Hilos\I18n\DTO\TabLanguage;

/**
 * Decides the language and the region a tab shows: the language ladder first, then the region
 * ladder for the language it decided. On each ladder the first rung that fits wins.
 *
 * The language ladder: the person's choice when that language is switched on; without a choice,
 * the first language the browser asks for that is switched on ({@see BrowserLanguages}); then the
 * language of the default locale of the connection's country when that language is switched on;
 * otherwise the default language. A choice that does not fit sends the tab straight to the default
 * language, past the browser and the country, and is left as it is.
 *
 * The region ladder, for the decided language: the person's chosen locale when it belongs to
 * that language and is available ({@see Locales::isAvailable()}); the variant of that language for
 * the connection's country when it is available; the first variant of that language the browser
 * asks for that is available; the countryless locale of that language when it is available; the
 * countryless locale of the default language when it is switched on; and at the bottom no stored
 * locale at all - the formats then come from the catalog's locale of the default language. The
 * country's and the browser's variants are taken whatever rung decided the language.
 *
 * The country of the connection stands fourth on the language ladder - below the address, the
 * choice and the browser - and second on the region ladder, ahead of the browser. It arrives as an
 * ISO 3166-1 alpha-2 code, and its rungs do not know where the code came from (the sessions library
 * looks it up, HIL-2310). A country switched off counts as an unknown one on both ladders. The
 * default locale of a country gives its language even when that locale itself is switched off;
 * the region then comes from the rungs below.
 *
 * The rung of the language address joins later (HIL-2313), above the choice. A rung writes nothing
 * and remembers nothing: the ladder reads the reference and its four inputs only.
 */
final class TabLanguageLadder
{
    /**
     * @param ?int $chosenLanguageId Primary id of the language the person chose; null when there is no choice
     * @param ?int $chosenLocaleId Primary id of the locale the person chose; null when there is no choice
     * @param BrowserLanguages $browser Languages the tab's browser asks for; an empty request skips the browser's rungs
     * @param ?string $connectionCountry ISO 3166-1 alpha-2 code of the connection's country, in either case; null when the
     *     country is unknown, which skips the country's rungs
     * @return ?TabLanguage The tab's language and region; null while the default language has no row yet, whatever the choice
     * @throws EnvException When the default language key is absent from the environment catalog
     * @throws EnvInvalidValueException When the default language code is absent or unknown
     * @throws ItemNotFoundException When a row vanishes between its lookup and its read
     * @throws DatabaseException When reading the reference fails
     * @throws InvalidArgumentException When a loaded object has the wrong type
     * @throws LogicException When collection classes are not configured or the catalog has no locale of the default language
     * @throws HilosException When a locale's language or country, or a country's default locale, cannot be read
     */
    public static function resolve(
        ?int $chosenLanguageId,
        ?int $chosenLocaleId,
        BrowserLanguages $browser,
        ?string $connectionCountry,
    ): ?TabLanguage {
        $defaultCode = DefaultLanguage::code();
        if (!isset(Hilos::$db->languages[$defaultCode])) {
            return null;
        }
        $country = self::country($connectionCountry);
        $language = self::language($chosenLanguageId, $browser, $country, $defaultCode);
        $locale = self::locale($language, $chosenLocaleId, $browser, $country, $defaultCode);

        return new TabLanguage(
            $language->id,
            $language->code,
            $language->rtl,
            $locale?->id,
            $locale?->code,
            $locale === null ? self::catalogFormats($defaultCode) : LocaleFormats::ofLocale($locale),
        );
    }

    /**
     * The installation's row of the connection's country as both ladders take it. Codes of countries
     * are stored in the catalog's lower case, and a lookup answers them in upper case.
     *
     * @param ?string $connectionCountry ISO 3166-1 alpha-2 code of the connection's country, in either case, or null
     * @return ?Country The country's row when it is switched on; null when the code is null, has no row or its row is off
     * @throws ItemNotFoundException When the row vanishes between its lookup and its read
     * @throws DatabaseException When reading countries fails
     * @throws InvalidArgumentException When a loaded object has the wrong type
     * @throws LogicException When collection classes are not configured
     */
    private static function country(?string $connectionCountry): ?Country
    {
        if ($connectionCountry === null) {
            return null;
        }
        $code = strtolower($connectionCountry);

        return isset(Hilos::$db->countries[$code]) && Hilos::$db->countries[$code]->enabled
            ? Hilos::$db->countries[$code]
            : null;
    }

    /**
     * @param ?int $chosenLanguageId Primary id of the chosen language, or null
     * @param BrowserLanguages $browser Languages the browser asks for
     * @param ?Country $country The connection's switched-on country, or null
     * @param string $defaultCode Code of the default language
     * @return Language The chosen language when it exists and is switched on, the default one when it does not;
     *     without a choice the first switched-on language the browser asks for, then the language of the country's
     *     default locale when that language is switched on, else the default one
     * @throws ItemNotFoundException When the default language has no row, or a row vanishes between its lookup and its read
     * @throws DatabaseException When reading languages fails
     * @throws InvalidArgumentException When a loaded object has the wrong type
     * @throws LogicException When collection classes are not configured
     * @throws HilosException When the country's default locale or its language cannot be read
     */
    private static function language(?int $chosenLanguageId, BrowserLanguages $browser, ?Country $country, string $defaultCode): Language
    {
        if ($chosenLanguageId !== null) {
            return isset(Hilos::$db->languages[$chosenLanguageId]) && Hilos::$db->languages[$chosenLanguageId]->enabled
                ? Hilos::$db->languages[$chosenLanguageId]
                : Hilos::$db->languages[$defaultCode];
        }
        foreach ($browser->languages() as $code) {
            if (isset(Hilos::$db->languages[$code]) && Hilos::$db->languages[$code]->enabled) {
                return Hilos::$db->languages[$code];
            }
        }
        if ($country?->defaultLocale?->language->enabled === true) {
            return $country->defaultLocale->language;
        }

        return Hilos::$db->languages[$defaultCode];
    }

    /**
     * @param Language $language The decided language
     * @param ?int $chosenLocaleId Primary id of the chosen locale, or null
     * @param BrowserLanguages $browser Languages the browser asks for
     * @param ?Country $country The connection's switched-on country, or null
     * @param string $defaultCode Code of the default language
     * @return ?Locale The first stored locale the region ladder takes; null when it ends below every stored rung
     * @throws ItemNotFoundException When a row vanishes between its lookup and its read
     * @throws DatabaseException When reading locales fails
     * @throws InvalidArgumentException When a loaded object has the wrong type
     * @throws LogicException When collection classes are not configured
     * @throws HilosException When a locale's language or country cannot be read
     */
    private static function locale(
        Language $language,
        ?int $chosenLocaleId,
        BrowserLanguages $browser,
        ?Country $country,
        string $defaultCode,
    ): ?Locale {
        if ($chosenLocaleId !== null
            && isset(Hilos::$db->locales[$chosenLocaleId])
            && Hilos::$db->locales[$chosenLocaleId]->languageId === $language->id
            && Hilos::$db->locales->isAvailable(Hilos::$db->locales[$chosenLocaleId])
        ) {
            return Hilos::$db->locales[$chosenLocaleId];
        }
        if ($country !== null) {
            $variantCode = ObjectLocale::codeFor($language->code, $country->code);
            if (isset(Hilos::$db->locales[$variantCode]) && Hilos::$db->locales->isAvailable(Hilos::$db->locales[$variantCode])) {
                return Hilos::$db->locales[$variantCode];
            }
        }
        foreach ($browser->variantsOf($language->code) as $code) {
            if (isset(Hilos::$db->locales[$code]) && Hilos::$db->locales->isAvailable(Hilos::$db->locales[$code])) {
                return Hilos::$db->locales[$code];
            }
        }
        if (isset(Hilos::$db->locales[$language->code])
            && Hilos::$db->locales->isAvailable(Hilos::$db->locales[$language->code])
        ) {
            return Hilos::$db->locales[$language->code];
        }
        if (isset(Hilos::$db->locales[$defaultCode]) && Hilos::$db->locales[$defaultCode]->enabled) {
            return Hilos::$db->locales[$defaultCode];
        }

        return null;
    }

    /**
     * Formats at the bottom of the region ladder: the catalog's countryless locale of the default
     * language, or its first locale when the catalog has no countryless one (`de` has `de-DE`).
     *
     * @param string $defaultCode Code of the default language, always one the catalog knows
     * @return LocaleFormats Display formats of that catalog locale
     * @throws LogicException When the catalog holds no locale of the default language at all
     */
    private static function catalogFormats(string $defaultCode): LocaleFormats
    {
        $countryless = BuiltInI18nCatalog::locale($defaultCode);
        if ($countryless !== null) {
            return LocaleFormats::ofCatalog($countryless);
        }
        foreach (BuiltInI18nCatalog::locales() as $definition) {
            if ($definition->languageCode === $defaultCode) {
                return LocaleFormats::ofCatalog($definition);
            }
        }

        throw new LogicException("The built-in catalog holds no locale of the default language '{$defaultCode}'");
    }
}
