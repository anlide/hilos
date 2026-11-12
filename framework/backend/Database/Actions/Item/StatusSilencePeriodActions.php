<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Item;

use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Actions\Exception\ObjectCollectionNullException;
use Hilos\Database\Object\Item\StatusSilencePeriod as ObjectStatusSilencePeriod;
use Hilos\Database\View\Item\StatusSilencePeriod;
use Hilos\HilosException;

/**
 * Removal of one known closed silence fixture.
 *
 * @extends DbActions<StatusSilencePeriod, ObjectStatusSilencePeriod>
 * @property-read ObjectStatusSilencePeriod $object
 */
class StatusSilencePeriodActions extends DbActions
{
    /** @throws HilosException When ownership or persistence refuses test-only removal */
    public function delete(): void
    {
        $this->ensureCanWrite(TruthSourceOperation::Remove);
        $id = $this->object->getIdString();
        $this->object->delete();
        $collection = $this->getObjectCollection()
            ?? throw new ObjectCollectionNullException('Silence period removal requires its owning collection');
        unset($collection[$id]);
    }
}
