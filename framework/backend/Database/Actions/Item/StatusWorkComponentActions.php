<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Item;

use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Actions\Exception\ObjectCollectionNullException;
use Hilos\Database\Object\Item\StatusWorkComponent as ObjectStatusWorkComponent;
use Hilos\Database\View\Item\StatusWorkComponent;
use Hilos\HilosException;

/**
 * Joins the caller's transaction; no external side effects occur here.
 *
 * @extends DbActions<StatusWorkComponent, ObjectStatusWorkComponent>
 * @property-read ObjectStatusWorkComponent $object
 */
class StatusWorkComponentActions extends DbActions
{
    /** @throws HilosException When ownership or persistence refuses removal */
    public function delete(): void
    {
        $this->ensureCanWrite(TruthSourceOperation::Remove);
        $id = $this->object->getIdString();
        $this->object->delete();
        $collection = $this->getObjectCollection()
            ?? throw new ObjectCollectionNullException('Component removal requires its owning collection');
        unset($collection[$id]);
    }
}
