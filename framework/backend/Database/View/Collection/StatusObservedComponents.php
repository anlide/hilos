<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Database\Actions\Collection\StatusObservedComponentsActions;
use Hilos\Database\Object\Collection\StatusObservedComponents as ObjectStatusObservedComponents;
use Hilos\Database\View\Item\StatusObservedComponent;
use Hilos\HilosException;

/**
 * Array access uses the internal persisted primary id; the component key is read by its finder.
 *
 * @extends DbCollection<StatusObservedComponent, ObjectStatusObservedComponents>
 * @method ObjectStatusObservedComponents|null getObjectCollection()
 * @method StatusObservedComponent|null first()
 * @method StatusObservedComponent|null last()
 * @method StatusObservedComponent offsetGet(mixed $offset)
 * @property-read StatusObservedComponentsActions $actions Write-once observation starts
 */
class StatusObservedComponents extends DbCollection
{
    public const string DB_ITEM_CLASS = StatusObservedComponent::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectStatusObservedComponents::class;

    /**
     * @param string $componentKey Status component key
     * @return ?StatusObservedComponent Observation start of the component, or null when none is recorded
     * @throws HilosException When storage cannot be read
     */
    public function findByComponentKey(string $componentKey): ?StatusObservedComponent
    {
        $object = $this->objectCollection->findByComponentKey($componentKey);
        return $object === null ? null : $this->createDbItem($object);
    }
}
