<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\ProbeValues as EntityProbeValues;
use Hilos\Database\Entity\Item\ProbeValue as EntityProbeValue;
use Hilos\Database\Object\Item\ProbeValue as ObjectProbeValue;
use Hilos\Database\Object\Objects;

/**
 * Probe values, loaded whole on first read so key lookups use the local copy.
 *
 * @extends Objects<ObjectProbeValue>
 * @method ObjectProbeValue|null first()
 * @method ObjectProbeValue|null last()
 * @method ObjectProbeValue|null get(int|string $key)
 * @method ObjectProbeValue|null offsetGet(mixed $offset)
 */
class ProbeValues extends Objects
{
    public const string OBJECT_CLASS = ObjectProbeValue::class;
    public const string ENTITY_COLLECTION_CLASS = EntityProbeValues::class;
    public const string COLLECTION_KEY = HilosDbContext::probeValues;

    /**
     * @param string $key Probe key
     * @return ?ObjectProbeValue Probe row or null when absent
     * @throws DatabaseException When loading the probe values fails
     * @throws InvalidArgumentException When the entity column is invalid
     * @throws LogicException When collection classes are not configured
     */
    public function findByKey(string $key): ?ObjectProbeValue
    {
        if ($key === '') {
            return null;
        }

        foreach ($this->loadByColumn(EntityProbeValue::key, $key) as $id) {
            return $this->objects[$id];
        }

        return null;
    }
}
