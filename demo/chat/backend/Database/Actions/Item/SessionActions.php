<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Actions\Item;

use Demo\Chat\Database\Actions\Collection\SessionsActions;
use Demo\Chat\Database\Object\Item\Session as ObjectSession;
use Demo\Chat\Database\View\Item\Session;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Database\Actions\Item\DbActions;
use Hilos\HilosException;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * SessionActions - write operations for a single Session item.
 *
 * @extends DbActions<Session, ObjectSession>
 * @property-read ObjectSession $object
 */
final class SessionActions extends DbActions
{
    /**
     * Binds this session to a durable user (login/register), refreshing its expiry.
     *
     * @param int $userId User id to bind the session to
     * @throws ItemNotFoundForUpdateException When the session is not persisted (id is null)
     * @throws HilosException On database error
     */
    public function bindUser(int $userId): void
    {
        $this->ensureCanWrite();

        if ($this->object->id === null) {
            throw new ItemNotFoundForUpdateException('Session not found for bindUser (id is null)');
        }

        $this->object->userId = $userId;
        $this->object->lastSeenAt = TimeHelper::getSqlDateTime();
        $this->object->expiresAt = SessionsActions::expiryFromNow();
        $this->object->sync();
    }

    /**
     * Reverts this session to anonymous (logout), keeping the token alive.
     *
     * @throws ItemNotFoundForUpdateException When the session is not persisted (id is null)
     * @throws HilosException On database error
     */
    public function unbindUser(): void
    {
        $this->ensureCanWrite();

        if ($this->object->id === null) {
            throw new ItemNotFoundForUpdateException('Session not found for unbindUser (id is null)');
        }

        $this->object->userId = null;
        $this->object->lastSeenAt = TimeHelper::getSqlDateTime();
        $this->object->sync();
    }

    /**
     * Refreshes last-seen and expiry after activity on this session.
     *
     * @throws ItemNotFoundForUpdateException When the session is not persisted (id is null)
     * @throws HilosException On database error
     */
    public function touch(): void
    {
        $this->ensureCanWrite();

        if ($this->object->id === null) {
            throw new ItemNotFoundForUpdateException('Session not found for touch (id is null)');
        }

        $this->object->lastSeenAt = TimeHelper::getSqlDateTime();
        $this->object->expiresAt = SessionsActions::expiryFromNow();
        $this->object->sync();
    }
}
