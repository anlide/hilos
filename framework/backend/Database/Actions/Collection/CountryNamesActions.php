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
