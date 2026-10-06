<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Actions\Item\LocaleActions;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\Object\Item\Locale as ObjectLocale;
use Hilos\HilosException;
use Hilos\Hilos;
use Hilos\Database\Context\HilosDbContext;
use Hilos\I18n\MeasurementSystem;

/**
 * Read-facing locale row.
 *
 * @extends DbItem<ObjectLocale>
 * @property-read LocaleActions $actions
 * @property-read ?int $id
 * @property-read string $code
 * @property-read int $languageId
 * @property-read ?int $countryId
 * @property-read string $dateFormat
 * @property-read string $timeFormat
 * @property-read string $numberFormat
 * @property-read string $phoneFormat
 * @property-read string $addressFormat
 * @property-read MeasurementSystem $measurementSystem
 * @property-read string $collation
 * @property-read bool $enabled
 * @property-read Language $language
 * @property-read ?Country $country
 */
class Locale extends DbItem
{
    public const string language = HilosDbContext::language;
    public const string country = HilosDbContext::country;

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
            ObjectLocale::id => $this->_object->id,
            ObjectLocale::code => $this->_object->code,
            ObjectLocale::languageId => $this->_object->languageId,
            ObjectLocale::countryId => $this->_object->countryId,
            ObjectLocale::dateFormat => $this->_object->dateFormat,
            ObjectLocale::timeFormat => $this->_object->timeFormat,
            ObjectLocale::numberFormat => $this->_object->numberFormat,
            ObjectLocale::phoneFormat => $this->_object->phoneFormat,
            ObjectLocale::addressFormat => $this->_object->addressFormat,
            ObjectLocale::measurementSystem => MeasurementSystem::from($this->_object->measurementSystem),
            ObjectLocale::collation => $this->_object->collation,
            ObjectLocale::enabled => $this->_object->enabled,
            self::language => Hilos::$db->languages[$this->_object->languageId],
            self::country => Hilos::$db->countries[$this->_object->countryId],
            default => parent::__get($name),
        };
    }
}
