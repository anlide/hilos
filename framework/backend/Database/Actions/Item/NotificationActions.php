<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Item;

use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\ItemNotFoundForDeleteException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Actions\Exception\ObjectCollectionNullException;
use Hilos\Database\Object\Item\Notification as ObjectNotification;
use Hilos\Database\View\Item\Notification;
use Hilos\Environment\NonProductionGate;
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
class NotificationActions extends DbActions
{
    /**
     * Deletes one test notification under the recipient's existing Remove claim.
     *
     * @throws ValidationException When the environment is not admitted for test writes
     * @throws ItemNotFoundForDeleteException When the notification has no persisted identity
     * @throws ObjectCollectionNullException When the object is detached from its collection
     * @throws HilosException When ownership, deletion or the announcement refuses the write
     */
    public function deleteForTest(): void
    {
        if (!NonProductionGate::admitted()) {
            throw new ValidationException('Notification cleanup is test-only.');
        }
        $this->ensureCanWrite(TruthSourceOperation::Remove);
        if ($this->object->id === null) {
            throw new ItemNotFoundForDeleteException('Notification not found for delete (id is null)');
        }
        $collection = $this->getObjectCollection()
            ?? throw new ObjectCollectionNullException('Notification is detached from its collection');
        $id = $this->object->getIdString();
        $this->object->delete();
        unset($collection[$id]);
    }

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

        $this->object->readAt = TimeHelper::nowMs();
        $this->object->sync();
    }
}
