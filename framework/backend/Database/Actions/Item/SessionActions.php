<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Item;

use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Database\Actions\Collection\SessionsActions;
use Hilos\Database\Object\Item\Session as ObjectSession;
use Hilos\Database\View\Item\Session;
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
     * Records or clears the impersonator marker on this session (HIL-166).
     *
     * The user rebind itself is done through the session host's authenticateSession
     * seam; this writes only the marker that remembers the admin behind the takeover.
     * Pass the admin id when starting an impersonation, or null when stopping.
     *
     * @param ?int $impersonatorUserId Admin id to record, or null to clear the marker
     * @throws ItemNotFoundForUpdateException When the session is not persisted (id is null)
     * @throws HilosException On database error
     */
    public function setImpersonator(?int $impersonatorUserId): void
    {
        $this->ensureCanWrite();

        if ($this->object->id === null) {
            throw new ItemNotFoundForUpdateException('Session not found for setImpersonator (id is null)');
        }

        $this->object->impersonatorUserId = $impersonatorUserId;
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

    /**
     * Ages this session's expiry into the past so the next resolution reads it as expired.
     *
     * Test-only support for the `test:session:expire` CLI (HIL-397): rewrites
     * `expires_at` to just before now through the same guarded write path as
     * {@see touch()}/{@see bindUser()}, so an e2e can drive the session-expiry logout
     * (enforcement is HIL-398) without waiting out the session TTL.
     *
     * @throws ItemNotFoundForUpdateException When the session is not persisted (id is null)
     * @throws HilosException On database error
     */
    public function expire(): void
    {
        $this->ensureCanWrite();

        if ($this->object->id === null) {
            throw new ItemNotFoundForUpdateException('Session not found for expire (id is null)');
        }

        $this->object->expiresAt = date('Y-m-d H:i:s', time() - 1);
        $this->object->sync();
    }
}
