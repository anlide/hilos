<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Item\Setting as EntitySetting;
use Hilos\Database\Object\Item\Object_;

/**
 * Setting object - wraps Setting entity.
 *
 * @extends Object_<EntitySetting>
 *
 * @property-read ?int $id
 * @property string $key
 * @property string $type
 * @property ?string $value
 */
final class Setting extends Object_
{
    public const string ENTITY_CLASS = EntitySetting::class;

    /**
     * Returns the database collection key.
     *
     * @return string Collection key (HilosDbContext::settings)
     */
    protected static function getCollectionKey(): string
    {
        return HilosDbContext::settings;
    }

    /**
     * Magic getter for entity properties.
     *
     * @param string $property Property name (id, key, type, value)
     * @return mixed Property value
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            'id' => $this->entity->id,
            'key' => $this->entity->key,
            'type' => $this->entity->type,
            'value' => $this->entity->value,
            default => parent::__get($property),
        };
    }

    /**
     * Magic setter for entity properties.
     *
     * @param string $property Property name (key, type, value)
     * @param mixed $value Value to set
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            'key' => $this->entity->key = (string)$value,
            'type' => $this->entity->type = (string)$value,
            'value' => $this->entity->value = is_scalar($value) ? (string)$value : null,
            default => parent::__set($property, $value),
        };
    }

    /**
     * Check if this setting is orphan (key exists in DB but not in catalog).
     *
     * @param array<string, array<string, mixed>> $catalog Catalog: key => [type, default_value]
     * @return bool True if key is not in catalog
     */
    public function isOrphan(array $catalog): bool
    {
        return !array_key_exists($this->entity->key, $catalog);
    }

    /**
     * Converts setting to associative array.
     *
     * @return array<string, mixed> Setting data (id, key, type, value)
     */
    public function toArray(): array
    {
        return [
            'id' => $this->entity->id,
            'key' => $this->entity->key,
            'type' => $this->entity->type,
            'value' => $this->entity->value,
        ];
    }
}
