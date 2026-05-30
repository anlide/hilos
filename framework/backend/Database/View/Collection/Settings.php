<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\Actions\Collection\SettingsActions;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\Settings as ObjectSettings;
use Hilos\Database\View\Item\Setting;

/**
 * Settings Db collection.
 *
 * Settings support key-based array access: `$settings[$settingKey]`.
 * Integer offsets are still treated as DB primary keys.
 *
 * @extends DbCollection<Setting, ObjectSettings>
 * @property-read SettingsActions $actions
 */
final class Settings extends DbCollection
{
    public const string DB_ITEM_CLASS = Setting::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectSettings::class;

    /**
     * Checks whether a setting exists by setting key or primary id.
     *
     * @param mixed $offset Setting key string or primary id integer
     * @return bool True when the setting exists
     * @throws DatabaseException On database error while resolving the setting
     * @throws LogicException When collection class constants are not configured
     * @throws InvalidArgumentException When object type does not match the collection
     */
    public function offsetExists(mixed $offset): bool
    {
        return $this->offsetGet($offset) !== null;
    }

    /**
     * Returns a setting by setting key or primary id.
     *
     * @param mixed $offset Setting key string or primary id integer
     * @return ?Setting Setting Db item or null if not found
     * @throws DatabaseException On database error while resolving the setting
     * @throws LogicException When collection class constants are not configured
     * @throws InvalidArgumentException When object type does not match the collection
     */
    public function offsetGet(mixed $offset): ?Setting
    {
        if (is_string($offset)) {
            return $this->findByKey($offset);
        }
        if (!is_int($offset)) {
            return null;
        }

        /** @var ?Setting $setting */
        $setting = parent::offsetGet($offset);
        return $setting;
    }

    /**
     * Finds a setting by key for the collection offset implementation.
     *
     * @param string $key Setting key
     * @return ?Setting Setting Db item or null if not found
     * @throws DatabaseException On database error while loading the setting
     * @throws LogicException When collection class constants are not configured
     * @throws InvalidArgumentException When object type does not match the collection
     */
    private function findByKey(string $key): ?Setting
    {
        $objectSetting = $this->objectCollection->findByKey($key);

        if ($objectSetting?->id === null) {
            return null;
        }

        /** @var ?Setting $setting */
        $setting = $this->getItemForKey($objectSetting->id);
        return $setting;
    }

    /**
     * Returns orphans (settings in DB whose key is not in catalog).
     *
     * @param array<string, array<string, mixed>> $catalog Catalog: key => [type, default_value]
     * @return list<Setting> Orphan Db items
     * @throws DatabaseException On database error while loading settings
     * @throws LogicException When collection class constants are not configured
     * @throws InvalidArgumentException When object type does not match the collection
     */
    public function getOrphans(array $catalog): array
    {
        $objectOrphans = $this->objectCollection->getOrphans($catalog);
        $result = [];
        foreach ($objectOrphans as $objectSetting) {
            $item = $this->getItemForKey($objectSetting->id);
            if ($item !== null) {
                $result[] = $item;
            }
        }
        return $result;
    }
}
