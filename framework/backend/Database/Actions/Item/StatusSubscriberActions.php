<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Item;

use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Actions\Exception\ObjectCollectionNullException;
use Hilos\Database\Object\Item\StatusSubscriber as ObjectStatusSubscriber;
use Hilos\Database\View\Item\StatusSubscriber;
use Hilos\HilosException;
use Hilos\Status\StatusSubscriberConfirmation;
use Random\RandomException;

/**
 * Challenge writes join the caller's transaction; delete revokes both links by removing the row.
 *
 * @extends DbActions<StatusSubscriber, ObjectStatusSubscriber>
 * @property-read ObjectStatusSubscriber $object
 */
class StatusSubscriberActions extends DbActions
{
    /**
     * @param int $at Issue time in UTC epoch milliseconds
     * @return ?StatusSubscriberConfirmation Replacement challenge, or null for an already confirmed or deleted subscription
     * @throws HilosException When the owner grant, transaction or persistence refuses the write
     * @throws RandomException When secure entropy is unavailable
     */
    public function issueConfirmation(int $at): ?StatusSubscriberConfirmation
    {
        $this->ensureCanWrite();
        return $this->object->issueConfirmation($at);
    }

    /**
     * @param string $token Presented raw confirmation value
     * @param int $at Confirmation time in UTC epoch milliseconds
     * @return ?int Subscription id won by this call, or null for a stale, expired, or already spent challenge
     * @throws HilosException When the owner grant, transaction or persistence refuses the write
     */
    public function confirm(string $token, int $at): ?int
    {
        $this->ensureCanWrite();
        return $this->object->confirm($token, $at);
    }

    /** @throws HilosException When ownership or persistence refuses removal */
    public function delete(): void
    {
        $this->ensureCanWrite(TruthSourceOperation::Remove);
        $id = $this->object->getIdString();
        $this->object->delete();
        $collection = $this->getObjectCollection()
            ?? throw new ObjectCollectionNullException('Subscription removal requires its owning collection');
        unset($collection[$id]);
    }
}
