<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Collection\Settings as EntitySettings;
use Hilos\Database\Entity\Item\Setting as EntitySetting;
use Hilos\Database\Object\Item\Setting as ObjectSetting;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Objects;
use Hilos\HilosException;

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
class Settings extends Objects
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
     * @throws InvalidArgumentException When the entity column is invalid
     * @throws LogicException When collection classes are not configured
     */
    public function findByKey(string $key): ?ObjectSetting
    {
        if ($key === '') {
            return null;
        }

        foreach ($this->loadByColumn(EntitySetting::key, $key) as $id) {
            return $this->objects[$id];
        }

        return null;
    }

    /**
     * Returns orphans (settings in DB whose key is not in catalog).
     *
     * @param array<string, array<string, mixed>> $catalog Catalog: key => [type, default_value]
     * @return static Orphan settings
     * @throws LogicException When the entity collection class is not configured
     * @throws DatabaseException When loading all settings from the database fails
     * @throws HilosException When the empty collection refuses direct initialization
     */
    public function getOrphans(array $catalog): static
    {
        // The same read as any other first read of the collection: a row findByKey() already
        // holds keeps its instance under the wrapper handed out for it.
        $this->loadWholeIfPromised();
        $result = static::initEmpty();
        foreach ($this->objects as $object) {
            if ($object instanceof ObjectSetting && $object->isOrphan($catalog)) {
                $result->hydrate($object->id, $object);
            }
        }
        return $result;
    }
}
