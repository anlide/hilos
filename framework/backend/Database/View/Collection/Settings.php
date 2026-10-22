<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\Actions\Collection\SettingsActions;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\View\Collection\ItemNotFoundException;
use Hilos\Database\Exception\View\CollectionNotManualException;
use Hilos\Database\Object\Collection\Settings as ObjectSettings;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\View\Item\Setting;
use Hilos\HilosException;

/**
 * Settings Db collection.
 *
 * Settings support key-based array access: `$settings[$settingKey]`.
 * Integer offsets are still treated as DB primary keys.
 *
 * @extends DbCollection<Setting, ObjectSettings>
 * @method ObjectSettings|null getObjectCollection()
 * @method Setting|null first()
 * @method Setting|null last()
 * @property-read SettingsActions $actions
 */
class Settings extends DbCollection
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
        if (is_string($offset)) {
            return $this->findByKey($offset) !== null;
        }
        if (!is_int($offset)) {
            return false;
        }

        return $this->getItemForKey($offset) !== null;
    }

    /**
     * Returns a setting by setting key or primary id; a key with no setting refuses.
     *
     * @param mixed $offset Setting key string or primary id integer of a row the caller requires
     * @return Setting Setting Db item at the key or id
     * @throws ItemNotFoundException When no setting holds the key or id, `null` or another unsupported offset included
     * @throws DatabaseException On database error while resolving the setting
     * @throws LogicException When collection class constants are not configured
     * @throws InvalidArgumentException When object type does not match the collection
     */
    public function offsetGet(mixed $offset): Setting
    {
        if (is_string($offset)) {
            return $this->findByKey($offset) ?? throw new ItemNotFoundException(static::class, $offset);
        }
        if (!is_int($offset)) {
            throw new ItemNotFoundException(static::class, $offset);
        }

        /** @var Setting $setting */
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
     * @return static Orphan Db items
     * @throws DatabaseException On database error while loading settings
     * @throws LogicException When collection class constants are not configured
     * @throws InvalidArgumentException When object type does not match the collection
     * @throws HilosException When the empty object collection refuses direct initialization
     * @throws ObjectGetIdStringNotImplementedException If DbItem's Object does not implement getIdString()
     * @throws CollectionNotManualException When add() rejects the item on a non-manual collection
     */
    public function getOrphans(array $catalog): static
    {
        $objectOrphans = $this->objectCollection->getOrphans($catalog);
        $result = static::initEmpty();
        foreach ($objectOrphans as $objectSetting) {
            $item = $this->getItemForKey($objectSetting->id);
            if ($item !== null) {
                $result->add($item);
            }
        }
        return $result;
    }
}
