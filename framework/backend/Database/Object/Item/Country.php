<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\Country as EntityCountry;
use Hilos\Database\Object\Collection\Countries as ObjectCountries;

/**
 * Scalar country row.
 *
 * @extends Object_<EntityCountry>
 * @property-read ?int $id
 * @property string $code
 * @property string $currencySymbol
 * @property string $currencyCode
 * @property ?int $defaultLocaleId
 * @property bool $enabled
 */
class Country extends Object_
{
    public const string ENTITY_CLASS = EntityCountry::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectCountries::class;
    public const string id = 'id';
    public const string code = 'code';
    public const string currencySymbol = 'currencySymbol';
    public const string currencyCode = 'currencyCode';
    public const string defaultLocaleId = 'defaultLocaleId';
    public const string enabled = 'enabled';

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
            self::currencySymbol => $this->entity->currency_symbol,
            self::currencyCode => $this->entity->currency_code,
            self::defaultLocaleId => $this->entity->default_locale_id,
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
            self::currencySymbol => $this->entity->currency_symbol = (string)$value,
            self::currencyCode => $this->entity->currency_code = (string)$value,
            self::defaultLocaleId => $this->entity->default_locale_id = $value === null ? null : (int)$value,
            self::enabled => $this->entity->enabled = (bool)$value,
            default => parent::__set($property, $value),
        };
    }

    /** @return array<string, mixed> Scalar fields of the country */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::code => $this->entity->code,
            self::currencySymbol => $this->entity->currency_symbol,
            self::currencyCode => $this->entity->currency_code,
            self::defaultLocaleId => $this->entity->default_locale_id,
            self::enabled => $this->entity->enabled,
        ];
    }
}
