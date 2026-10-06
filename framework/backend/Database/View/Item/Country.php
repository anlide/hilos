<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Actions\Item\CountryActions;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\Object\Item\Country as ObjectCountry;
use Hilos\HilosException;
use Hilos\Hilos;

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
 * @property-read ?Locale $defaultLocale
 */
class Country extends DbItem
{
    public const string defaultLocale = 'defaultLocale';

    /**
     * @param string $name Scalar property, relation or actions name
     * @return mixed Field value or the inherited item member
     * @throws PropertyNotFoundException When the property is unknown
     * @throws ActionsClassException When item actions are not configured
     * @throws HilosException When the inherited getter refuses the member
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
            self::defaultLocale => Hilos::$db->locales[$this->_object->defaultLocaleId],
            default => parent::__get($name),
        };
    }
}
