<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\Language as EntityLanguage;
use Hilos\Database\Object\Collection\Languages as ObjectLanguages;

/**
 * Scalar language row.
 *
 * @extends Object_<EntityLanguage>
 * @property-read ?int $id
 * @property string $code
 * @property string $nativeName
 * @property bool $rtl
 * @property bool $enabled
 */
class Language extends Object_
{
    public const string ENTITY_CLASS = EntityLanguage::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectLanguages::class;
    public const string id = 'id';
    public const string code = 'code';
    public const string nativeName = 'nativeName';
    public const string rtl = 'rtl';
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
            self::nativeName => $this->entity->native_name,
            self::rtl => $this->entity->rtl,
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
            self::nativeName => $this->entity->native_name = (string)$value,
            self::rtl => $this->entity->rtl = (bool)$value,
            self::enabled => $this->entity->enabled = (bool)$value,
            default => parent::__set($property, $value),
        };
    }

    /** @return array<string, mixed> Scalar fields of the language */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::code => $this->entity->code,
            self::nativeName => $this->entity->native_name,
            self::rtl => $this->entity->rtl,
            self::enabled => $this->entity->enabled,
        ];
    }
}
