<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Core\Exception\ValidationException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\SqlRuntime\DuplicateEntryException;
use Hilos\Database\Object\Collection\StatusObservedComponents as ObjectStatusObservedComponents;
use Hilos\Database\View\Collection\StatusObservedComponents as DbCollectionStatusObservedComponents;
use Hilos\Database\View\Item\StatusObservedComponent;
use Hilos\HilosException;
use Hilos\Status\StatusComponentDefinition;

/**
 * Write-once records of the moment each Status component came under observation (HIL-1887).
 *
 * @extends DbActions<StatusObservedComponent, ObjectStatusObservedComponents>
 * @property-read DbCollectionStatusObservedComponents $collection
 * @property-read ObjectStatusObservedComponents $objectCollection
 */
class StatusObservedComponentsActions extends DbActions
{
    /**
     * Records when a component came under observation, once: an existing row is returned unchanged
     * whatever moment is offered, and so is the row of a concurrent writer that won the insert.
     *
     * @param string $componentKey Status component key
     * @param int $observedSince Start of observation in signed UTC epoch milliseconds
     * @return StatusObservedComponent Recorded observation start, existing when the component already had one
     * @throws ValidationException When the key does not match the Status component key pattern
     * @throws DatabaseException When a lost insert race leaves no committed winner to read
     * @throws HilosException When ownership or persistence refuses the record
     */
    public function record(string $componentKey, int $observedSince): StatusObservedComponent
    {
        $this->ensureCanCreate();
        if (preg_match(StatusComponentDefinition::KEY_PATTERN, $componentKey) !== 1) {
            throw new ValidationException('The Status component key is malformed.');
        }
        $existing = $this->collection->findByComponentKey($componentKey);
        if ($existing !== null) {
            return $existing;
        }
        $objectClass = $this->objectCollection::OBJECT_CLASS;
        $component = $objectClass::create();
        $component->component_key = $componentKey;
        $component->observed_since = $observedSince;
        try {
            $component->sync();
        } catch (DuplicateEntryException $failure) {
            $winner = $this->objectCollection->currentByComponentKey($componentKey)
                ?? throw new DatabaseException('The observation start of this component vanished after a duplicate key', previous: $failure);
            return $this->createDbItemFromObject($winner);
        }
        $this->addObjectToCollection($component);
        return $this->createDbItemFromObject($component);
    }
}
