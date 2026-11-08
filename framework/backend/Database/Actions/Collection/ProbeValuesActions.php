<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Database\Object\Collection\ProbeValues as ObjectProbeValues;
use Hilos\Database\View\Collection\ProbeValues as DbCollectionProbeValues;
use Hilos\Database\View\Item\ProbeValue;
use Hilos\HilosException;

/**
 * Creates synthetic database probe rows under their node's set claim.
 *
 * @extends DbActions<ProbeValue, ObjectProbeValues>
 * @property-read DbCollectionProbeValues $collection
 * @property-read ObjectProbeValues $objectCollection
 */
class ProbeValuesActions extends DbActions
{
    /**
     * @param string $nodeId Node whose set the new row belongs to
     * @param string $key Unique probe key
     * @param string $value Probe value to store
     * @return ProbeValue Created probe row
     * @throws HilosException When ownership, the database or the collection refuses the write
     */
    public function add(string $nodeId, string $key, string $value): ProbeValue
    {
        $this->ensureCanCreateInSet($nodeId);

        $objectClass = $this->objectCollection::OBJECT_CLASS;
        $probeValue = $objectClass::create();
        $probeValue->nodeId = $nodeId;
        $probeValue->key = $key;
        $probeValue->value = $value;
        $probeValue->sync();
        $this->addObjectToCollection($probeValue);

        return $this->createDbItemFromObject($probeValue);
    }
}
