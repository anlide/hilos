<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Collection\Settings as EntitySettings;
use Hilos\Database\Entity\Item\Setting as EntitySetting;
use Hilos\Database\Object\Item\Setting as ObjectSetting;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Objects;

/**
 * Settings object collection.
 *
 * @extends Objects<ObjectSetting>
 * @method ObjectSetting|null current()
 * @method ObjectSetting|null first()
 * @method ObjectSetting|null last()
 * @method ObjectSetting|null get(int|string $key)
 * @method ObjectSetting|null offsetGet(mixed $offset)
 */
final class Settings extends Objects
{
    public const string OBJECT_CLASS = ObjectSetting::class;
    public const string ENTITY_COLLECTION_CLASS = EntitySettings::class;
    public const string COLLECTION_KEY = HilosDbContext::settings;

    /**
     * Finds setting by key.
     *
     * @param string $key Setting key
     * @return ?ObjectSetting Setting object or null if not found
     * @throws DatabaseException If database query fails
     */
    public function findByKey(string $key): ?ObjectSetting
    {
        if ($key === '') {
            return null;
        }

        $entitySetting = EntitySetting::get([EntitySetting::key => $key])->first();

        if ($entitySetting === null) {
            return null;
        }

        if (!isset($this->objects[$entitySetting->id])) {
            $this->objects[$entitySetting->id] = ObjectSetting::fromEntity($entitySetting);
        }

        return $this->objects[$entitySetting->id];
    }

    /**
     * Returns orphans (settings in DB whose key is not in catalog).
     *
     * @param array<string, array<string, mixed>> $catalog Catalog: key => [type, default_value]
     * @return list<ObjectSetting> Orphan settings
     */
    public function getOrphans(array $catalog): array
    {
        if (!$this->_allLoaded) {
            $this->loadAllFromDB();
        }
        $orphans = [];
        foreach ($this->objects as $object) {
            if ($object instanceof ObjectSetting && $object->isOrphan($catalog)) {
                $orphans[] = $object;
            }
        }
        return $orphans;
    }
}
