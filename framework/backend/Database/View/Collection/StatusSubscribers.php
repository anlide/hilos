<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Database\Actions\Collection\StatusSubscribersActions;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\StatusSubscribers as ObjectStatusSubscribers;
use Hilos\Database\View\Item\StatusSubscriber;
use Hilos\HilosException;
use Hilos\Status\StatusSubscriberCountsData;

/**
 * Array access uses the persisted subscriber primary id; business keys use named lookups.
 *
 * @extends DbCollection<StatusSubscriber, ObjectStatusSubscribers>
 * @method ObjectStatusSubscribers|null getObjectCollection()
 * @method StatusSubscriber|null first()
 * @method StatusSubscriber|null last()
 * @method StatusSubscriber offsetGet(mixed $offset)
 * @property-read StatusSubscribersActions $actions Subscription creation
 */
class StatusSubscribers extends DbCollection
{
    public const int MAX_PORTION_ROWS = 32;
    public const string DB_ITEM_CLASS = StatusSubscriber::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectStatusSubscribers::class;

    /**
     * @param string $email Address normalized at the lookup boundary
     * @return ?StatusSubscriber Matching subscription
     * @throws HilosException When the lookup fails
     */
    public function findByEmail(string $email): ?StatusSubscriber
    {
        $object = $this->objectCollection->findByEmail($email);
        return $object === null ? null : $this->createDbItem($object);
    }

    /**
     * @param string $token Presented raw confirmation value
     * @return ?StatusSubscriber Matching non-secret row; confirm performs the atomic expiry check
     * @throws HilosException When the lookup fails
     */
    public function findByConfirmationToken(string $token): ?StatusSubscriber
    {
        $object = $this->objectCollection->findByConfirmationToken($token);
        return $object === null ? null : $this->createDbItem($object);
    }

    /**
     * @param string $token Presented permanent unsubscribe value
     * @return ?StatusSubscriber Matching non-secret subscription
     * @throws HilosException When the lookup fails
     */
    public function findByUnsubscribeToken(string $token): ?StatusSubscriber
    {
        $object = $this->objectCollection->findByUnsubscribeToken($token);
        return $object === null ? null : $this->createDbItem($object);
    }

    /**
     * @return StatusSubscriberCountsData Fresh aggregate and audience upper bound
     * @throws HilosException When storage or the aggregate query is unavailable
     */
    public function counts(): StatusSubscriberCountsData
    {
        return $this->objectCollection->counts();
    }

    /**
     * @param int $eventAt Audience cutoff in UTC epoch milliseconds
     * @param int $maxSubscriberId Captured upper id of the pass
     * @param ?int $afterId Last processed id, null for the first portion
     * @param int $limit Requested rows, capped at MAX_PORTION_ROWS
     * @return static Detached confirmed recipients in ascending id order
     * @throws HilosException When storage, query execution or selection initialization fails
     */
    public function confirmedRecipients(int $eventAt, int $maxSubscriberId, ?int $afterId, int $limit): static
    {
        $selection = static::init();
        $selection->setObjectCollection(($this->getObjectCollection()
            ?? throw new DatabaseException('Subscribers require a database-backed collection'))
            ->confirmedRecipients($eventAt, $maxSubscriberId, $afterId, $limit));
        return $selection;
    }
}
