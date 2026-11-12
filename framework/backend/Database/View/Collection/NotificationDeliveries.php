<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Database\Actions\Collection\NotificationDeliveriesActions;
use Hilos\Database\Actions\Exception\ObjectCollectionNullException;
use Hilos\Database\ChangeLog\Section\ChangeLogReadCollection;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\TableNotActivatedException;
use Hilos\Database\Object\Collection\NotificationDeliveries as ObjectNotificationDeliveries;
use Hilos\Database\View\Item\NotificationDelivery;
use Hilos\Notification\Delivery\ExpiredDeliveryRef;
use Hilos\Notification\Delivery\NotificationDeliveryIdentity;

/**
 * NotificationDeliveries Db collection.
 *
 * Read-facing representation of the framework-owned hilos_notification_delivery
 * table (HIL-196). The dispatcher creates pending rows and the channel agents drive
 * them through the object collection; the delivery-logs UI (HIL-201) reads them.
 *
 * @extends DbCollection<NotificationDelivery, ObjectNotificationDeliveries>
 * @method ObjectNotificationDeliveries|null getObjectCollection()
 * @method NotificationDelivery|null first()
 * @method NotificationDelivery|null last()
 * @method NotificationDelivery offsetGet(mixed $offset)
 * @property-read NotificationDeliveriesActions $actions
 */
class NotificationDeliveries extends DbCollection
{
    public const string DB_ITEM_CLASS = NotificationDelivery::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectNotificationDeliveries::class;

    /**
     * @param int $deliveryId Delivery primary key
     * @return ?NotificationDeliveryIdentity Fresh saved coordinates, null when absent
     * @throws ObjectCollectionNullException When detached from the owning collection
     * @throws TableNotActivatedException When the project has not activated the delivery table
     * @throws DatabaseException When the projection query or binding fails
     */
    public function readIdentity(int $deliveryId): ?NotificationDeliveryIdentity
    {
        return ($this->getObjectCollection()
            ?? throw new ObjectCollectionNullException('Notification deliveries are detached from their collection'))
            ->readIdentity($deliveryId);
    }

    /**
     * @param string $channel Saved channel to scan
     * @param int $afterId Exclusive delivery primary-key cursor
     * @param int $limit Positive page size
     * @return ChangeLogReadCollection<NotificationDeliveryIdentity> Dense saved snapshot in ascending delivery-id order
     * @throws ObjectCollectionNullException When detached from the owning collection
     * @throws InvalidArgumentException When the page limit is not positive
     * @throws TableNotActivatedException When the project has not activated the delivery table
     * @throws DatabaseException When the projection query or binding fails
     */
    public function pendingIdentitiesAfter(string $channel, int $afterId, int $limit): ChangeLogReadCollection
    {
        return ($this->getObjectCollection()
            ?? throw new ObjectCollectionNullException('Notification deliveries are detached from their collection'))
            ->pendingIdentitiesAfter($channel, $afterId, $limit);
    }

    /**
     * SCAFFOLD: no live caller until the erasure coordinator reconciles its delivery owners (HIL-1952).
     *
     * @param int $recipientUserId Recipient whose notifications the deliveries belong to
     * @param int $afterId Exclusive delivery primary-key cursor
     * @param int $limit Positive page size
     * @return ChangeLogReadCollection<NotificationDeliveryIdentity> Dense saved snapshot in ascending delivery-id order
     * @throws ObjectCollectionNullException When detached from the owning collection
     * @throws InvalidArgumentException When the page limit is not positive
     * @throws TableNotActivatedException When the project has not activated the delivery table
     * @throws DatabaseException When the projection query or binding fails
     */
    public function recipientIdentitiesAfter(int $recipientUserId, int $afterId, int $limit): ChangeLogReadCollection
    {
        return ($this->getObjectCollection()
            ?? throw new ObjectCollectionNullException('Notification deliveries are detached from their collection'))
            ->recipientIdentitiesAfter($recipientUserId, $afterId, $limit);
    }

    /**
     * @param string $channel Saved channel to scan
     * @param int $cutoffMs Exclusive creation-age boundary in epoch milliseconds
     * @param int $afterCreatedAt Creation moment of the exclusive cursor in epoch milliseconds
     * @param int $afterId Delivery primary key breaking ties at the cursor moment
     * @param int $limit Positive page size
     * @return ChangeLogReadCollection<ExpiredDeliveryRef> Dense saved snapshot ordered by creation moment, then delivery id
     * @throws ObjectCollectionNullException When detached from the owning collection
     * @throws InvalidArgumentException When the page limit is not positive
     * @throws TableNotActivatedException When the project has not activated the delivery table
     * @throws DatabaseException When the projection query or binding fails
     */
    public function expiredIdentitiesAfter(
        string $channel,
        int $cutoffMs,
        int $afterCreatedAt,
        int $afterId,
        int $limit,
    ): ChangeLogReadCollection {
        return ($this->getObjectCollection()
            ?? throw new ObjectCollectionNullException('Notification deliveries are detached from their collection'))
            ->expiredIdentitiesAfter($channel, $cutoffMs, $afterCreatedAt, $afterId, $limit);
    }
}
