<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Actions\Item\LocaleActions;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\Object\Item\Locale as ObjectLocale;
use Hilos\Database\View\Collection\CountryNames;
use Hilos\Database\View\Collection\LanguageNames;
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
 * @property-read LanguageNames $languageNames
 * @property-read CountryNames $countryNames
 */
class Locale extends DbItem
{
    public const string language = HilosDbContext::language;
    public const string country = HilosDbContext::country;
    public const string languageNames = HilosDbContext::languageNames;
    public const string countryNames = HilosDbContext::countryNames;

    public const string DELETE_ENABLED = 'enabled';
    public const string DELETE_COUNTRY_DEFAULT = 'country-default';
    public const string DELETE_NAMES = 'names';

    /**
     * @param string $name Scalar property, relation or actions name
     * @return mixed Field value or the inherited item member
     * @throws PropertyNotFoundException When the property is unknown
     * @throws ActionsClassException When item actions are not configured
     * @throws HilosException When a relation lookup or the inherited getter fails
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
            self::country => Hilos::$db->countries[$this->_object->countryId] ?? null,
            self::languageNames => Hilos::$db->languageNames->forLocaleId($this->_object->id),
            self::countryNames => Hilos::$db->countryNames->forLocaleId($this->_object->id),
            default => parent::__get($name),
        };
    }

    /** @return ?string First current deletion refusal, or null when the locale is unreferenced and off */
    public function deleteReason(): ?string
    {
        if ($this->enabled) {
            return self::DELETE_ENABLED;
        }
        if ($this->id !== null && $this->country?->defaultLocaleId === $this->id) {
            return self::DELETE_COUNTRY_DEFAULT;
        }
        if (count($this->languageNames) > 0 || count($this->countryNames) > 0) {
            return self::DELETE_NAMES;
        }

        return null;
    }
}
