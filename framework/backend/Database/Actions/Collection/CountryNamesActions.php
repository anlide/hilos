<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Core\Exception\ValidationException;
use Hilos\Database\Entity\Item\CountryName as EntityCountryName;
use Hilos\Database\Object\Collection\CountryNames as ObjectCountryNames;
use Hilos\Database\View\Collection\CountryNames as DbCollectionCountryNames;
use Hilos\Database\View\Item\Country;
use Hilos\Database\View\Item\Language;
use Hilos\Database\View\Item\Locale;
use Hilos\Database\View\Item\CountryName;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\I18n\Catalog\BuiltInI18nCatalog;

/**
 * @extends DbActions<CountryName, ObjectCountryNames>
 * @property-read DbCollectionCountryNames $collection
 * @property-read ObjectCountryNames $objectCollection
 */
class CountryNamesActions extends DbActions
{
    /**
     * @param Country $country Named country
     * @param Language $language Writing language
     * @param ?Locale $locale Override locale, or null for base
     * @param string $name Literal name, including an empty string
     * @return CountryName Locked manual row
     * @throws ValidationException When references or name are invalid
     * @throws HilosException When ownership or persistence refuses the write
     */
    public function createManual(Country $country, Language $language, ?Locale $locale, string $name): CountryName
    {
        return $this->createName($country, $language, $locale, $name, true);
    }

    /**
     * @param Country $country Named country
     * @param Language $language Writing language
     * @param string $name Catalog name
     * @return CountryName Unlocked base row
     * @throws ValidationException When references or name are invalid
     * @throws HilosException When ownership or persistence refuses the write
     */
    public function createCatalogBase(Country $country, Language $language, string $name): CountryName
    {
        return $this->createName($country, $language, null, $name, false);
    }

    /**
     * Takes one base name in from the catalog: creates it unlocked when missing, refreshes it
     * when it is unlocked and its text differs, and leaves a locked one as it is (HIL-1472).
     *
     * A country or a language the catalog does not know has no catalog name, and nothing is
     * written. Locale overrides are never touched. Opens no transaction: the reflow, the default
     * language provision and the section's own doors call it inside theirs.
     *
     * @param Country $country Named country
     * @param Language $language Writing language
     * @throws ValidationException When references or the catalog name are invalid
     * @throws HilosException When ownership or persistence refuses the write
     */
    public function takeFromCatalog(Country $country, Language $language): void
    {
        $name = BuiltInI18nCatalog::countryName($country->code, $language->code);
        if ($name === null) {
            return;
        }
        $countryId = $country->id;
        $languageId = $language->id;
        if ($countryId === null || $languageId === null) {
            throw new ValidationException('Name references must exist');
        }
        $base = $this->collection->findBase($countryId, $languageId);
        if ($base === null) {
            $this->createCatalogBase($country, $language, $name);
            return;
        }
        if ($base->name !== $name) {
            $base->actions->refreshFromCatalog($name);
        }
    }

    /**
     * Takes in the catalog's base names of every built-in country stored here, written in one
     * language: takeFromCatalog() for each. A language the catalog does not know gets no row.
     *
     * @param Language $language Writing language
     * @throws ValidationException When references or a catalog name are invalid
     * @throws HilosException When ownership or persistence refuses a write
     */
    public function takeAllFromCatalog(Language $language): void
    {
        foreach (BuiltInI18nCatalog::countries() as $definition) {
            $country = Hilos::$db->countries[$definition->code];
            if ($country !== null) {
                $this->takeFromCatalog($country, $language);
            }
        }
    }

    /**
     * @param Country $country Named country
     * @param Language $language Writing language
     * @param ?Locale $locale Override locale, or null for base
     * @param string $name Literal name
     * @param bool $locked Manual rows are locked
     * @return CountryName New name row
     * @throws ValidationException When references or name are invalid
     * @throws HilosException When ownership or persistence refuses the write
     */
    private function createName(Country $country, Language $language, ?Locale $locale, string $name, bool $locked): CountryName
    {
        $countryId = $country->id;
        $languageId = $language->id;
        if ($countryId === null || Hilos::$db->countries[$countryId] === null
            || $languageId === null || Hilos::$db->languages[$languageId] === null) {
            throw new ValidationException('Name references must exist');
        }
        $this->ensureCanCreateInSet((string)$countryId);
        if (mb_strlen($name) > EntityCountryName::NAME_MAX_CHARS) {
            throw new ValidationException('Name exceeds its column width');
        }
        if ($locale !== null) {
            if ($locale->id === null || Hilos::$db->locales[$locale->id] === null
                || $locale->languageId !== $languageId
                || $this->collection->findBase($countryId, $languageId) === null) {
                throw new ValidationException('Override needs its base and a locale of the writing language');
            }
        }

        $objectClass = $this->objectCollection::OBJECT_CLASS;
        $row = $objectClass::create();
        $row->countryId = $countryId;
        $row->languageId = $languageId;
        $row->localeId = $locale?->id;
        $row->name = $name;
        $row->locked = $locked;
        $row->sync();
        $this->addObjectToCollection($row);
        return $this->createDbItemFromObject($row);
    }
}
