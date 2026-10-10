<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Core\Exception\ValidationException;
use Hilos\Database\Object\Collection\Locales as ObjectLocales;
use Hilos\Database\Object\Item\Locale as ObjectLocale;
use Hilos\Database\View\Collection\Locales as DbCollectionLocales;
use Hilos\Database\View\Item\Country;
use Hilos\Database\View\Item\Language;
use Hilos\Database\View\Item\Locale;
use Hilos\HilosException;
use Hilos\I18n\Catalog\BuiltInI18nCatalog;
use Hilos\I18n\Catalog\LocaleDefinition;
use Hilos\I18n\MeasurementSystem;
use Hilos\I18n\LocaleTemplates;

/**
 * @extends DbActions<Locale, ObjectLocales>
 * @property-read DbCollectionLocales $collection
 * @property-read ObjectLocales $objectCollection
 */
class LocalesActions extends DbActions
{
    /**
     * @param Language $language The locale's language
     * @param ?Country $country Its country, or null for a countryless language locale
     * @param string $dateFormat Date template
     * @param string $timeFormat Time template
     * @param string $numberFormat Number template
     * @param string $phoneFormat Phone template
     * @param string $addressFormat Address template
     * @param MeasurementSystem $measurementSystem Unit system
     * @param string $collation Sorting template
     * @return Locale New switched-off locale
     * @throws ValidationException When the pair exists or a format is not a known template
     * @throws HilosException When ownership, the database or collection refuses the write
     */
    public function create(
        Language $language,
        ?Country $country,
        string $dateFormat,
        string $timeFormat,
        string $numberFormat,
        string $phoneFormat,
        string $addressFormat,
        MeasurementSystem $measurementSystem,
        string $collation,
    ): Locale {
        $this->ensureCanCreateInSet((string)$language->id);

        $code = ObjectLocale::codeFor($language->code, $country?->code);
        if ($this->collection[$code] !== null) {
            throw new ValidationException("Locale '{$code}' already exists.");
        }
        LocaleTemplates::refuseUnknown($dateFormat, $timeFormat, $numberFormat, $phoneFormat, $addressFormat, $collation);

        $objectClass = $this->objectCollection::OBJECT_CLASS;
        $locale = $objectClass::create();
        $locale->code = $code;
        $locale->languageId = $language->id;
        $locale->countryId = $country?->id;
        $locale->dateFormat = $dateFormat;
        $locale->timeFormat = $timeFormat;
        $locale->numberFormat = $numberFormat;
        $locale->phoneFormat = $phoneFormat;
        $locale->addressFormat = $addressFormat;
        $locale->measurementSystem = $measurementSystem->value;
        $locale->collation = $collation;
        $locale->enabled = false;
        $locale->sync();
        $this->addObjectToCollection($locale);

        return $this->createDbItemFromObject($locale);
    }

    /**
     * Refreshes the switched-off built-in locales from the catalog: all seven formats, written
     * together when any of them differs. A missing locale is not created - a person adds a
     * locale - and a switched-on one is left as it is (HIL-1472).
     *
     * The walk is over the catalog by locale code, so a locale the catalog does not know is
     * never reached. Opens no transaction: the reflow calls it inside its own.
     *
     * @throws ValidationException When a catalog value is not a known template
     * @throws HilosException When ownership or persistence refuses a write
     */
    public function refreshFromCatalog(): void
    {
        foreach (BuiltInI18nCatalog::locales() as $definition) {
            $locale = $this->collection[$definition->code];
            if ($locale === null || $locale->enabled || self::matchesCatalog($locale, $definition)) {
                continue;
            }
            $locale->actions->update(
                $definition->dateFormat,
                $definition->timeFormat,
                $definition->numberFormat,
                $definition->phoneFormat,
                $definition->addressFormat,
                MeasurementSystem::from($definition->measurementSystem),
                $definition->collation,
            );
        }
    }

    /**
     * @param Locale $locale Stored locale
     * @param LocaleDefinition $definition Its catalog entry
     * @return bool Whether all seven formats already equal the catalog's
     */
    private static function matchesCatalog(Locale $locale, LocaleDefinition $definition): bool
    {
        return $locale->dateFormat === $definition->dateFormat
            && $locale->timeFormat === $definition->timeFormat
            && $locale->numberFormat === $definition->numberFormat
            && $locale->phoneFormat === $definition->phoneFormat
            && $locale->addressFormat === $definition->addressFormat
            && $locale->measurementSystem->value === $definition->measurementSystem
            && $locale->collation === $definition->collation;
    }
}
