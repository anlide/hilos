<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\Actions\Collection\ProbeValuesActions;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\View\Collection\ItemNotFoundException;
use Hilos\Database\Object\Collection\ProbeValues as ObjectProbeValues;
use Hilos\Database\View\Item\ProbeValue;

/**
 * Cluster database probe values.
 *
 * ProbeValues support key-based array access: `$probeValues[$key]`.
 * Integer offsets are still treated as DB primary keys.
 *
 * @extends DbCollection<ProbeValue, ObjectProbeValues>
 * @method ObjectProbeValues|null getObjectCollection()
 * @method ProbeValue|null first()
 * @method ProbeValue|null last()
 * @property-read ProbeValuesActions $actions
 */
class ProbeValues extends DbCollection
{
    public const string DB_ITEM_CLASS = ProbeValue::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectProbeValues::class;

    /**
     * Checks whether a probe value exists by key or primary id.
     *
     * @param mixed $offset Probe key string or primary id integer
     * @return bool True when the probe value exists
     * @throws DatabaseException On database error while resolving the probe value
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
     * Returns a probe value by key or primary id; a key with no probe value refuses.
     *
     * @param mixed $offset Probe key string or primary id integer of a row the caller requires
     * @return ProbeValue Probe row at the key or id
     * @throws ItemNotFoundException When no probe value holds the key or id, `null` or another unsupported offset included
     * @throws DatabaseException On database error while resolving the probe value
     * @throws LogicException When collection class constants are not configured
     * @throws InvalidArgumentException When object type does not match the collection
     */
    public function offsetGet(mixed $offset): ProbeValue
    {
        if (is_string($offset)) {
            return $this->findByKey($offset) ?? throw new ItemNotFoundException(static::class, $offset);
        }
        if (!is_int($offset)) {
            throw new ItemNotFoundException(static::class, $offset);
        }

        /** @var ProbeValue $probeValue */
        $probeValue = parent::offsetGet($offset);
        return $probeValue;
    }

    /**
     * Finds a probe value by key for the collection offset implementation.
     *
     * @param string $key Probe key
     * @return ?ProbeValue Probe row or null if not found
     * @throws DatabaseException On database error while loading the probe value
     * @throws LogicException When collection class constants are not configured
     * @throws InvalidArgumentException When object type does not match the collection
     */
    private function findByKey(string $key): ?ProbeValue
    {
        $objectProbeValue = $this->objectCollection->findByKey($key);

        if ($objectProbeValue?->id === null) {
            return null;
        }

        /** @var ?ProbeValue $probeValue */
        $probeValue = $this->getItemForKey($objectProbeValue->id);
        return $probeValue;
    }
}
