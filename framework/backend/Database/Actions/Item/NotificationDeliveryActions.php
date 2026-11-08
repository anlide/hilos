<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Item;

use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Actions\Exception\ObjectCollectionNullException;
use Hilos\Database\Object\Collection\NotificationDeliveries as ObjectNotificationDeliveries;
use Hilos\Database\Object\Item\NotificationDelivery as ObjectNotificationDelivery;
use Hilos\Database\View\Item\NotificationDelivery;
use Hilos\HilosException;

/**
 * @extends DbActions<NotificationDelivery, ObjectNotificationDelivery>
 * @property-read ObjectNotificationDelivery $object
 */
class NotificationDeliveryActions extends DbActions
{
    /** @throws HilosException When ownership, persistence or announcement refuses the attempt */
    public function beginAttempt(): void
    {
        $this->ensureCanWrite();
        $this->deliveryCollection()->beginAttempt($this->object);
    }

    /** @throws HilosException When ownership, persistence or announcement refuses settlement */
    public function markSent(): void
    {
        $this->ensureCanWrite();
        $this->deliveryCollection()->markSent($this->object);
    }

    /**
     * @param string $error Failure detail to truncate to the saved column's limit
     * @param int $maxAttempts Attempt ceiling for terminal failure
     * @throws HilosException When ownership, persistence or announcement refuses the failure
     */
    public function recordFailure(string $error, int $maxAttempts): void
    {
        $this->ensureCanWrite();
        $this->deliveryCollection()->recordFailure($this->object, $error, $maxAttempts);
    }

    /** @throws HilosException When ownership, persistence or announcement refuses the reset */
    public function resetForRetry(): void
    {
        $this->ensureCanWrite();
        $this->deliveryCollection()->resetForRetry($this->object);
    }

    /** @throws HilosException When ownership, deletion or the lost-membership announcement fails */
    public function delete(): void
    {
        $this->ensureCanWrite(TruthSourceOperation::Remove);
        $collection = $this->deliveryCollection();
        $id = $this->object->getIdString();
        $this->object->delete();
        unset($collection[$id]);
    }

    /**
     * @return ObjectNotificationDeliveries Owning collection providing the persistence primitives
     * @throws ObjectCollectionNullException When the delivery is detached from its collection
     */
    private function deliveryCollection(): ObjectNotificationDeliveries
    {
        return $this->getObjectCollection()
            ?? throw new ObjectCollectionNullException('Notification delivery is detached from its collection');
    }
}
