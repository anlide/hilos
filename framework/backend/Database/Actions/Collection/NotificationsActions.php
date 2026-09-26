<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Object\Collection\Notifications as ObjectNotifications;
use Hilos\Database\View\Collection\Notifications as DbCollectionNotifications;
use Hilos\Database\View\Item\Notification;
use Hilos\HilosException;

/**
 * NotificationsActions - write operations for the Notifications collection.
 *
 * Backs the `notification_mark_all_read` client action (HIL-102): marks every
 * unread notification of one recipient read in a single bulk statement scoped to
 * that user. The action is mounted on the notification-center page in HIL-195,
 * which passes the subscribed user's id.
 *
 * @extends DbActions<Notification, ObjectNotifications>
 * @property-read DbCollectionNotifications $collection
 * @property-read ObjectNotifications $objectCollection
 */
final class NotificationsActions extends DbActions
{
    /**
     * Marks every unread notification of a recipient read.
     *
     * @param int $userId Recipient user id whose notifications are marked read
     * @return int Number of notifications marked read
     * @throws HilosException On database error
     */
    public function markAllReadForUser(int $userId): int
    {
        return $this->objectCollection->markAllReadForUser($userId);
    }

    /**
     * Deletes every notification of a recipient - the account is being erased (HIL-302).
     *
     * @param int $userId Recipient user id
     * @throws WriteNotAllowedException When the truth source rejects the delete
     * @throws HilosException On database error
     */
    public function deleteForUser(int $userId): void
    {
        $this->ensureCanWriteSet((string)$userId, TruthSourceOperation::Remove);

        $this->objectCollection->deleteForUser($userId);
    }
}
