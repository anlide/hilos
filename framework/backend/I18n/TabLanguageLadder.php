<?php

declare(strict_types=1);

namespace Hilos\I18n;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\View\Collection\ItemNotFoundException;
use Hilos\Database\View\Collection\Locales;
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
 * the first language the browser asks for that is switched on ({@see BrowserLanguages}); otherwise
 * the default language. A choice that does not fit sends the tab straight to the default language,
 * past the browser, and is left as it is.
 *
 * The region ladder, for the decided language: the person's chosen locale when it belongs to
 * that language and is available ({@see Locales::isAvailable()}); the first variant of that
 * language the browser asks for that is available, whatever rung decided the language; the
 * countryless locale of that language when it is available; the countryless locale of the default
 * language when it is switched on; and at the bottom no stored locale at all - the formats then
 * come from the catalog's locale of the default language.
 *
 * Rungs of the language address and the country of the connection join later (HIL-2313,
 * HIL-2311): the address above the choice, and the country ahead of the browser on both ladders.
 * A rung writes nothing and remembers nothing: the ladder reads the reference and its three
 * inputs only.
 */
final class TabLanguageLadder
{
    /**
     * @param ?int $chosenLanguageId Primary id of the language the person chose; null when there is no choice
     * @param ?int $chosenLocaleId Primary id of the locale the person chose; null when there is no choice
     * @param BrowserLanguages $browser Languages the tab's browser asks for; an empty request skips the browser's rungs
     * @return ?TabLanguage The tab's language and region; null while the default language has no row yet, whatever the choice
     * @throws EnvException When the default language key is absent from the environment catalog
     * @throws EnvInvalidValueException When the default language code is absent or unknown
     * @throws ItemNotFoundException When a row vanishes between its lookup and its read
     * @throws DatabaseException When reading the reference fails
     * @throws InvalidArgumentException When a loaded object has the wrong type
     * @throws LogicException When collection classes are not configured or the catalog has no locale of the default language
     * @throws HilosException When a locale's language or country cannot be read
     */
    public static function resolve(?int $chosenLanguageId, ?int $chosenLocaleId, BrowserLanguages $browser): ?TabLanguage
    {
        $defaultCode = DefaultLanguage::code();
        if (!isset(Hilos::$db->languages[$defaultCode])) {
            return null;
        }
        $language = self::language($chosenLanguageId, $browser, $defaultCode);
        $locale = self::locale($language, $chosenLocaleId, $browser, $defaultCode);

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
     * @param ?int $chosenLanguageId Primary id of the chosen language, or null
     * @param BrowserLanguages $browser Languages the browser asks for
     * @param string $defaultCode Code of the default language
     * @return Language The chosen language when it exists and is switched on, the default one when it does not;
     *     without a choice the first switched-on language the browser asks for, else the default one
     * @throws ItemNotFoundException When the default language has no row, or a row vanishes between its lookup and its read
     * @throws DatabaseException When reading languages fails
     * @throws InvalidArgumentException When a loaded object has the wrong type
     * @throws LogicException When collection classes are not configured
     */
    private static function language(?int $chosenLanguageId, BrowserLanguages $browser, string $defaultCode): Language
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

        return Hilos::$db->languages[$defaultCode];
    }

    /**
     * @param Language $language The decided language
     * @param ?int $chosenLocaleId Primary id of the chosen locale, or null
     * @param BrowserLanguages $browser Languages the browser asks for
     * @param string $defaultCode Code of the default language
     * @return ?Locale The first stored locale the region ladder takes; null when it ends below every stored rung
     * @throws ItemNotFoundException When a row vanishes between its lookup and its read
     * @throws DatabaseException When reading locales fails
     * @throws InvalidArgumentException When a loaded object has the wrong type
     * @throws LogicException When collection classes are not configured
     * @throws HilosException When a locale's language or country cannot be read
     */
    private static function locale(Language $language, ?int $chosenLocaleId, BrowserLanguages $browser, string $defaultCode): ?Locale
    {
        if ($chosenLocaleId !== null
            && isset(Hilos::$db->locales[$chosenLocaleId])
            && Hilos::$db->locales[$chosenLocaleId]->languageId === $language->id
            && Hilos::$db->locales->isAvailable(Hilos::$db->locales[$chosenLocaleId])
        ) {
            return Hilos::$db->locales[$chosenLocaleId];
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
