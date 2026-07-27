<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Item;

use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Database\Object\Item\Notification as ObjectNotification;
use Hilos\Database\View\Item\Notification;
use Hilos\HilosException;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * NotificationActions - write operations for a single Notification item.
 *
 * Backs the `notification_mark_read` client action (HIL-102): stamping `read_at`
 * marks this notification read. The action is mounted on the notification-center
 * page in HIL-195, which resolves the item within the recipient's own set, so an
 * item action can never touch another user's row. Re-marking an already-read
 * notification is idempotent.
 *
 * @extends DbActions<Notification, ObjectNotification>
 * @property-read ObjectNotification $object
 */
final class NotificationActions extends DbActions
{
    /**
     * Marks this notification read, stamping the read time.
     *
     * @throws ItemNotFoundForUpdateException When the notification is not persisted (id is null)
     * @throws HilosException On database error
     */
    public function markRead(): void
    {
        $this->ensureCanWrite();

        if ($this->object->id === null) {
            throw new ItemNotFoundForUpdateException('Notification not found for markRead (id is null)');
        }

        if (!$this->object->isUnread()) {
            return;
        }

        $this->object->readAt = TimeHelper::getSqlDateTime();
        $this->object->sync();
    }
}
