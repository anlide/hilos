<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\Actions\Item\CountryActions;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\Object\Item\Country as ObjectCountry;
use Hilos\Database\View\Collection\Locales;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\I18n\Catalog\BuiltInI18nCatalog;

/**
 * Read-facing country row.
 *
 * @extends DbItem<ObjectCountry>
 * @property-read CountryActions $actions
 * @property-read ?int $id
 * @property-read string $code
 * @property-read string $currencySymbol
 * @property-read string $currencyCode
 * @property-read ?int $defaultLocaleId
 * @property-read bool $enabled
 * @property-read Locales $locales All locales of this country, on or off
 * @property-read ?Locale $defaultLocale
 */
class Country extends DbItem
{
    public const string defaultLocale = 'defaultLocale';

    public const string DELETE_KNOWN = 'known';
    public const string DELETE_LOCALES = 'locales';
    public const string DELETE_NAMES = 'names';

    /**
     * @param string $name Scalar property, relation or actions name
     * @return mixed Field value or the inherited item member
     * @throws PropertyNotFoundException When the property is unknown
     * @throws ActionsClassException When item actions are not configured
     * @throws HilosException When a relation cannot be read or the inherited getter refuses the member
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            ObjectCountry::id => $this->_object->id,
            ObjectCountry::code => $this->_object->code,
            ObjectCountry::currencySymbol => $this->_object->currencySymbol,
            ObjectCountry::currencyCode => $this->_object->currencyCode,
            ObjectCountry::defaultLocaleId => $this->_object->defaultLocaleId,
            ObjectCountry::enabled => $this->_object->enabled,
            HilosDbContext::locales => Hilos::$db->locales->forCountryId($this->_object->id),
            self::defaultLocale => Hilos::$db->locales[$this->_object->defaultLocaleId] ?? null,
            default => parent::__get($name),
        };
    }

    /**
     * @return ?string First deletion blocker: catalog, locales, names; null when none exists
     * @throws DatabaseException When dependent names cannot be read
     * @throws InvalidArgumentException When a dependent entity column is invalid
     * @throws LogicException When collection classes are not configured
     */
    public function deleteReason(): ?string
    {
        if (BuiltInI18nCatalog::country($this->_object->code) !== null) {
            return self::DELETE_KNOWN;
        }
        if (count($this->locales) > 0) {
            return self::DELETE_LOCALES;
        }
        if ($this->_object->id !== null && Hilos::$db->countryNames->hasForCountry($this->_object->id)) {
            return self::DELETE_NAMES;
        }

        return null;
    }
}
