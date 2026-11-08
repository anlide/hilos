<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Item;

use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Database\Object\Item\ProbeValue as ObjectProbeValue;
use Hilos\Database\View\Item\ProbeValue;
use Hilos\HilosException;

/**
 * Updates one probe value through the node's set ownership door.
 *
 * @extends DbActions<ProbeValue, ObjectProbeValue>
 * @property-read ObjectProbeValue $object
 */
class ProbeValueActions extends DbActions
{
    /**
     * @param string $value New probe value
     * @throws ItemNotFoundForUpdateException When the probe row has no persisted id
     * @throws HilosException When ownership or persistence refuses the update
     */
    public function updateValue(string $value): void
    {
        $this->ensureCanWrite();
        if ($this->object->id === null) {
            throw new ItemNotFoundForUpdateException('Probe value not found for update (id is null)');
        }

        $this->object->value = $value;
        $this->object->sync();
    }
}
