<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\CountryName as EntityCountryName;
use Hilos\Database\Object\Collection\CountryNames as ObjectCountryNames;

/**
 * Scalar translated name row.
 *
 * @extends Object_<EntityCountryName>
 * @property-read ?int $id
 * @property int $countryId
 * @property int $languageId
 * @property ?int $localeId
 * @property string $name
 * @property bool $locked
 */
class CountryName extends Object_
{
    public const string ENTITY_CLASS = EntityCountryName::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectCountryNames::class;
    public const string id = 'id';
    public const string countryId = 'countryId';
    public const string languageId = 'languageId';
    public const string localeId = 'localeId';
    public const string name = 'name';
    public const string locked = 'locked';

    /**
     * @param string $property Scalar property name
     * @return mixed Stored field value
     * @throws DatabaseException When the property is unknown
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::countryId => $this->entity->country_id,
            self::languageId => $this->entity->language_id,
            self::localeId => $this->entity->locale_id,
            self::name => $this->entity->name,
            self::locked => $this->entity->locked,
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
            self::countryId => $this->entity->country_id = (int)$value,
            self::languageId => $this->entity->language_id = (int)$value,
            self::localeId => $this->entity->locale_id = $value === null ? null : (int)$value,
            self::name => $this->entity->name = (string)$value,
            self::locked => $this->entity->locked = (bool)$value,
            default => parent::__set($property, $value),
        };
    }

    /** @return array<string, mixed> Scalar name fields */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::countryId => $this->entity->country_id,
            self::languageId => $this->entity->language_id,
            self::localeId => $this->entity->locale_id,
            self::name => $this->entity->name,
            self::locked => $this->entity->locked,
        ];
    }
}
