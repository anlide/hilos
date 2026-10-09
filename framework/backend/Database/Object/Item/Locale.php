<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\Locale as EntityLocale;
use Hilos\Database\Object\Collection\Locales as ObjectLocales;

/**
 * Scalar locale row.
 *
 * @extends Object_<EntityLocale>
 * @property-read ?int $id
 * @property string $code
 * @property int $languageId
 * @property ?int $countryId
 * @property string $dateFormat
 * @property string $timeFormat
 * @property string $numberFormat
 * @property string $phoneFormat
 * @property string $addressFormat
 * @property string $measurementSystem
 * @property string $collation
 * @property bool $enabled
 */
class Locale extends Object_
{
    public const string ENTITY_CLASS = EntityLocale::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectLocales::class;
    public const string id = 'id';
    public const string code = 'code';
    public const string languageId = 'languageId';
    public const string countryId = 'countryId';
    public const string dateFormat = 'dateFormat';
    public const string timeFormat = 'timeFormat';
    public const string numberFormat = 'numberFormat';
    public const string phoneFormat = 'phoneFormat';
    public const string addressFormat = 'addressFormat';
    public const string measurementSystem = 'measurementSystem';
    public const string collation = 'collation';
    public const string enabled = 'enabled';

    /**
     * Writes the code of the locale of one language and one country, or of the language alone.
     *
     * The one place of the rule: the creation of a locale stores its code by it, and the locales
     * table of a language keys the row of a pair by it, whether the pair has a locale or not (HIL-1476).
     *
     * @param string $languageCode Code of the language, as stored
     * @param ?string $countryCode Code of the country, as stored, or null for the language's own edition
     * @return string The language code, or the language code and the upper-cased country code joined by a hyphen
     */
    public static function codeFor(string $languageCode, ?string $countryCode): string
    {
        return $countryCode === null ? $languageCode : $languageCode . '-' . strtoupper($countryCode);
    }

    /**
     * @param string $property Scalar property name
     * @return mixed Stored field value
     * @throws DatabaseException When the property is unknown
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::code => $this->entity->code,
            self::languageId => $this->entity->language_id,
            self::countryId => $this->entity->country_id,
            self::dateFormat => $this->entity->date_format,
            self::timeFormat => $this->entity->time_format,
            self::numberFormat => $this->entity->number_format,
            self::phoneFormat => $this->entity->phone_format,
            self::addressFormat => $this->entity->address_format,
            self::measurementSystem => $this->entity->measurement_system,
            self::collation => $this->entity->collation,
            self::enabled => $this->entity->enabled,
            default => parent::__get($property),
        };
    }

    /**
     * @param string $property Writable scalar property name
     * @param mixed $value Value to persist
     * @throws DatabaseException When the property cannot be written
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::code => $this->entity->code = (string)$value,
            self::languageId => $this->entity->language_id = (int)$value,
            self::countryId => $this->entity->country_id = $value === null ? null : (int)$value,
            self::dateFormat => $this->entity->date_format = (string)$value,
            self::timeFormat => $this->entity->time_format = (string)$value,
            self::numberFormat => $this->entity->number_format = (string)$value,
            self::phoneFormat => $this->entity->phone_format = (string)$value,
            self::addressFormat => $this->entity->address_format = (string)$value,
            self::measurementSystem => $this->entity->measurement_system = (string)$value,
            self::collation => $this->entity->collation = (string)$value,
            self::enabled => $this->entity->enabled = (bool)$value,
            default => parent::__set($property, $value),
        };
    }

    /** @return array<string, mixed> Scalar fields of the locale */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::code => $this->entity->code,
            self::languageId => $this->entity->language_id,
            self::countryId => $this->entity->country_id,
            self::dateFormat => $this->entity->date_format,
            self::timeFormat => $this->entity->time_format,
            self::numberFormat => $this->entity->number_format,
            self::phoneFormat => $this->entity->phone_format,
            self::addressFormat => $this->entity->address_format,
            self::measurementSystem => $this->entity->measurement_system,
            self::collation => $this->entity->collation,
            self::enabled => $this->entity->enabled,
        ];
    }
}
