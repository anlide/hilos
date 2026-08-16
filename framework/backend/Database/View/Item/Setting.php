<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\Object\Item\Setting as ObjectSetting;
use Hilos\HilosException;

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
    /**
     * Magic getter for entity properties.
     *
     * @param string $name Property name (id, key, type, value)
     * @return mixed Property value (id, key, type, or value)
     * @throws PropertyNotFoundException If property does not exist
     * @throws ActionsClassException If item actions class is invalid or not configured
     * @throws HilosException Whatever the inherited getter raises
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            ObjectSetting::id => $this->_object->id,
            ObjectSetting::key => $this->_object->key,
            ObjectSetting::type => $this->_object->type,
            ObjectSetting::value => $this->_object->value,
            default => parent::__get($name),
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
        return $this->_object->isOrphan($catalog);
    }
}
