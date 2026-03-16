<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Database\Actions\Collection\SettingsActions;
use Hilos\Database\Actions\Item\SettingActions;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\Settings as ObjectSettings;
use Hilos\Database\View\Item\Setting;
use Hilos\Database\View\Collection\DbCollection;

/**
 * Settings Db collection.
 *
 * @extends DbCollection<Setting, ObjectSettings>
 * @property-read SettingsActions $actions
 * @property-read Setting|null offsetGet(mixed $offset)
 */
final class Settings extends DbCollection
{
    public const string DB_ITEM_CLASS = Setting::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectSettings::class;

    /**
     * Find setting by key.
     *
     * @param string $key Setting key
     * @return ?Setting Setting Db item or null if not found
     * @throws DatabaseException On database error
     */
    public function findByKey(string $key): ?Setting
    {
        $objectSetting = $this->objectCollection->findByKey($key);

        if ($objectSetting?->id === null) {
            return null;
        }

        return $this->getItemForKey($objectSetting->id);
    }

    /**
     * Returns orphans (settings in DB whose key is not in catalog).
     *
     * @param array<string, array<string, mixed>> $catalog Catalog: key => [type, default_value]
     * @return list<Setting> Orphan Db items
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
