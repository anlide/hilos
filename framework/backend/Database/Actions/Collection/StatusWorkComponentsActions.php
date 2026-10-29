<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\StatusWorkComponent as EntityStatusWorkComponent;
use Hilos\Database\Object\Collection\StatusWorkComponents as ObjectStatusWorkComponents;
use Hilos\Database\Object\Item\StatusWorkComponent as ObjectStatusWorkComponent;
use Hilos\Database\View\Collection\StatusWorkComponents as DbCollectionStatusWorkComponents;
use Hilos\Database\View\Item\StatusWorkComponent;
use Hilos\HilosException;

/**
 * Joins the caller's transaction; no external side effects occur here.
 *
 * @extends DbActions<StatusWorkComponent, ObjectStatusWorkComponents>
 * @property-read DbCollectionStatusWorkComponents $collection
 * @property-read ObjectStatusWorkComponents $objectCollection
 */
class StatusWorkComponentsActions extends DbActions
{
    /**
     * @param int $workId Persisted primary work id
     * @param string $componentKey Selected code-catalog key
     * @return StatusWorkComponent New persisted component selection
     * @throws HilosException When validation, ownership or persistence refuses creation
     */
    public function create(int $workId, string $componentKey): StatusWorkComponent
    {
        $this->ensureCanCreateInSet((string)$workId);
        if (!Database::hasOpenTransactions()) {
            throw new DatabaseException('Work and component writes require the caller transaction');
        }
        ObjectStatusWorkComponent::normalizeKeys([$componentKey]);
        $objectClass = $this->objectCollection::OBJECT_CLASS;
        $component = $objectClass::create();
        $component->work_id = $workId;
        $component->component_key = $componentKey;
        $component->sync();
        $this->addObjectToCollection($component);
        return $this->createDbItemFromObject($component);
    }

    /**
     * Replaces a whole child set inside the caller's transaction, preserving unchanged row ids.
     *
     * @param int $workId Persisted primary work id
     * @param string ...$componentKeys Complete selected code-key set
     * @throws HilosException When validation, ownership or persistence refuses replacement
     */
    public function replaceForWork(int $workId, string ...$componentKeys): void
    {
        $this->ensureCanCreateInSet((string)$workId);
        if (!Database::hasOpenTransactions()) {
            throw new DatabaseException('Work and component writes require the caller transaction');
        }
        $this->ensureCanWriteSet((string)$workId, TruthSourceOperation::Remove);
        $componentKeys = ObjectStatusWorkComponent::normalizeKeys($componentKeys);
        $remaining = array_fill_keys($componentKeys, true);
        foreach ($this->collection->whereColumnIs(EntityStatusWorkComponent::work_id, $workId) as $component) {
            if (isset($remaining[$component->componentKey])) {
                unset($remaining[$component->componentKey]);
            } else {
                $component->actions->delete();
            }
        }
        foreach (array_keys($remaining) as $key) {
            $this->create($workId, $key);
        }
    }
}
