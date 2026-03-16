<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Object\Item\Setting as ObjectSetting;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Database\View\Item\DbItem;

use Hilos\Database\Exception\View\Item\PropertyNotFoundException;

/**
 * Setting Db item - read-only wrapper around ObjectSetting.
 *
 * @extends DbItem<ObjectSetting>
 * @property-read ?int $id
 * @property-read string $key
 * @property-read string $type
 * @property-read ?string $value
 */
final class Setting extends DbItem
{
    public function __get(string $name): mixed
    {
        return match ($name) {
            'id' => $this->_object->id,
            'key' => $this->_object->key,
            'type' => $this->_object->type,
            'value' => $this->_object->value,
            default => parent::__get($name),
        };
    }

    /**
     * Check if this setting is orphan (key exists in DB but not in catalog).
     *
     * @param array<string, array<string, mixed>> $catalog Catalog: key => [type, default_value]
     */
    public function isOrphan(array $catalog): bool
    {
        return $this->_object->isOrphan($catalog);
    }

    /**
     * Get effective value (DB value or default from catalog when value is null).
     *
     * @param array<string, array<string, mixed>> $catalog Catalog: key => [type, default_value]
     */
    public function getEffectiveValue(array $catalog): mixed
    {
        $value = $this->_object->value;
        if ($value !== null) {
            return $value;
        }
        $entry = $catalog[$this->_object->key] ?? null;
        return $entry[SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE] ?? null;
    }
}
