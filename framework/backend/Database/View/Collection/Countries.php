<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\Actions\Collection\CountriesActions;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\View\Collection\ItemNotFoundException;
use Hilos\Database\Object\Collection\Countries as ObjectCountries;
use Hilos\Database\View\Item\Country;
use Hilos\Database\View\Item\Locale;
use Hilos\Environment\Exception\EnvException;
use Hilos\Environment\Exception\EnvInvalidValueException;
use Hilos\Hilos;
use Hilos\I18n\Catalog\BuiltInI18nCatalog;
use Hilos\I18n\DTO\CountryCardSummary;
use Hilos\I18n\DTO\CountryLocaleChoice;
use Hilos\I18n\DefaultLanguage;

/**
 * Countries support code strings and primary-id integers as offsets.
 *
 * @extends DbCollection<Country, ObjectCountries>
 * @property-read CountriesActions $actions
 * @method ObjectCountries|null getObjectCollection()
 * @method Country|null first()
 * @method Country|null last()
 */
class Countries extends DbCollection
{
    public const string DB_ITEM_CLASS = Country::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectCountries::class;

    /**
     * @param int $countryId Country primary id
     * @return CountryCardSummary Current read-only card facts
     * @throws DatabaseException When a country or dependent row query fails
     * @throws InvalidArgumentException When the country no longer exists
     * @throws LogicException When collection classes are not configured
     * @throws EnvException When the default language setting is absent
     * @throws EnvInvalidValueException When its configured code is unknown
     * @throws InvalidFormatException When a projected ID is not positive
     */
    public function cardSummary(int $countryId): CountryCardSummary
    {
        if (!isset($this[$countryId])) {
            throw new InvalidArgumentException("Country {$countryId} does not exist");
        }

        $country = $this[$countryId];
        $locales = $country->locales;
        $defaultLocale = $country->defaultLocale;
        $choices = array_values($locales->map(static fn (Locale $locale): CountryLocaleChoice => new CountryLocaleChoice(
            $locale->id,
            $locale->code,
            $locale->enabled,
        )));
        usort($choices, static fn (CountryLocaleChoice $a, CountryLocaleChoice $b): int => strcmp($a->code, $b->code));

        return new CountryCardSummary(
            name: $this->nameInDefaultLanguage($countryId),
            isOwn: BuiltInI18nCatalog::country($country->code) === null,
            defaultLocaleId: $country->defaultLocaleId,
            defaultLocaleCode: $defaultLocale?->code,
            defaultLocaleEnabled: $defaultLocale?->enabled,
            localeCount: count($locales),
            nameCount: Hilos::$db->countryNames->countBaseNamesForCountry($countryId),
            locales: $choices,
            catalogDefaultLocaleCode: BuiltInI18nCatalog::defaultLocale($country->code),
            deleteReason: $country->deleteReason(),
        );
    }

    /**
     * @param int $countryId Country primary id
     * @return ?string Base name in the default language, or null when missing or empty
     * @throws DatabaseException When the language or country name lookup fails
     * @throws LogicException When collection classes are not configured
     * @throws InvalidArgumentException When a loaded object has the wrong type
     * @throws EnvException When the default language setting is absent
     * @throws EnvInvalidValueException When its configured code is unknown
     */
    public function nameInDefaultLanguage(int $countryId): ?string
    {
        $defaultLanguageId = isset(Hilos::$db->languages[DefaultLanguage::code()])
            ? Hilos::$db->languages[DefaultLanguage::code()]->id
            : null;
        $name = $defaultLanguageId === null ? null : Hilos::$db->countryNames->findBase($countryId, $defaultLanguageId)?->name;

        return $name === '' ? null : $name;
    }

    /**
     * @param mixed $offset Country code or primary id
     * @return bool Whether the country exists
     * @throws DatabaseException When the lookup fails
     * @throws LogicException When collection classes are not configured
     * @throws InvalidArgumentException When a loaded object has the wrong type
     */
    public function offsetExists(mixed $offset): bool
    {
        if (is_string($offset)) {
            $object = $this->objectCollection->findByCode($offset);
            return $this->getItemForKey($object?->id) !== null;
        }
        if (!is_int($offset)) {
            return false;
        }

        return $this->getItemForKey($offset) !== null;
    }

    /**
     * @param mixed $offset Country code or primary id of a row the caller requires
     * @return Country Country at the code or id
     * @throws ItemNotFoundException When no country holds the code or id, `null` or another unsupported offset included
     * @throws DatabaseException When the lookup fails
     * @throws LogicException When collection classes are not configured
     * @throws InvalidArgumentException When a loaded object has the wrong type
     */
    public function offsetGet(mixed $offset): Country
    {
        if (is_string($offset)) {
            $object = $this->objectCollection->findByCode($offset);
            return $this->getItemForKey($object?->id) ?? throw new ItemNotFoundException(static::class, $offset);
        }
        if (!is_int($offset)) {
            throw new ItemNotFoundException(static::class, $offset);
        }

        /** @var Country $country */
        $country = parent::offsetGet($offset);
        return $country;
    }
}
