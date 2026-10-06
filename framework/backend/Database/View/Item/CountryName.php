<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Actions\Item\CountryNameActions;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\Object\Item\CountryName as ObjectCountryName;
use Hilos\Hilos;
use Hilos\HilosException;

/**
 * Read-facing translated country name.
 *
 * @extends DbItem<ObjectCountryName>
 * @property-read CountryNameActions $actions
 * @property-read ?int $id
 * @property-read int $countryId
 * @property-read int $languageId
 * @property-read ?int $localeId
 * @property-read string $name
 * @property-read bool $locked
 * @property-read Country $country
 * @property-read Language $language
 * @property-read ?Locale $locale
 */
class CountryName extends DbItem
{
    public const string country = 'country';
    public const string language = 'language';
    public const string locale = 'locale';

    /**
     * @param string $name Scalar property, relation or actions name
     * @return mixed Field value or inherited item member
     * @throws PropertyNotFoundException When the property is unknown
     * @throws ActionsClassException When item actions are not configured
     * @throws HilosException When a relation or inherited member refuses lookup
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            ObjectCountryName::id => $this->_object->id,
            ObjectCountryName::countryId => $this->_object->countryId,
            ObjectCountryName::languageId => $this->_object->languageId,
            ObjectCountryName::localeId => $this->_object->localeId,
            ObjectCountryName::name => $this->_object->name,
            ObjectCountryName::locked => $this->_object->locked,
            self::country => Hilos::$db->countries[$this->_object->countryId],
            self::language => Hilos::$db->languages[$this->_object->languageId],
            self::locale => Hilos::$db->locales[$this->_object->localeId],
            default => parent::__get($name),
        };
    }
}
