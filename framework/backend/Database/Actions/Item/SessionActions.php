<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Item;

use Hilos\Auth\Session\SessionToken;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Database\Actions\Collection\SessionsActions;
use Hilos\Database\Object\Collection\Sessions as ObjectSessions;
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
     * Moves this session onto a freshly minted token and binds it to a user in one write (HIL-582).
     *
     * The login half of the session-fixation cure: the row keeps its identity - id,
     * creation time, impersonator marker, and everything the analytics link to - and only
     * its secret name changes, so a token someone knew before the login no longer names
     * this session afterwards. One write rather than a rename beside {@see bindUser()},
     * because a session that is momentarily bound under its old token is the very window
     * being closed.
     *
     * The new token is refused when another session already holds it. The caller mints
     * again rather than proceeding: letting the login continue on the old token would
     * restore the vulnerability in the one place it matters most.
     *
     * @param string $newToken Freshly minted session token to move the row onto
     * @param int $userId User id to bind the session to
     * @throws InvalidFormatException When the new token is not a 32-character lowercase hex string
     * @throws DuplicateValueException When another session already holds the new token
     * @throws ItemNotFoundForUpdateException When the session is not persisted (id is null)
     * @throws HilosException On database error
     */
    public function rotateTokenAndBindUser(string $newToken, int $userId): void
    {
        $this->ensureCanWrite();

        if ($this->object->id === null) {
            throw new ItemNotFoundForUpdateException('Session not found for rotateTokenAndBindUser (id is null)');
        }

        SessionToken::ensureValid($newToken);

        // The unique key on the column is the real guard; asking the collection first turns
        // a collision into the answer the caller can act on - mint again - instead of a
        // database error indistinguishable from every other write failure.
        $sessions = $this->getObjectCollection();
        if ($sessions instanceof ObjectSessions && $sessions->findByToken($newToken) !== null) {
            throw new DuplicateValueException('Session with this token already exists');
        }

        $this->object->token = $newToken;
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
     * Remembers that this session is waiting on an address's registration code (HIL-612).
     *
     * The durable half of the unfinished-registration memory: the handshake serves the
     * code step from here, which is what lets a reloaded tab, a second tab and another
     * device all come back to it. A second call re-points the same row rather than
     * adding another - a person runs one registration at a time - and always restamps
     * the moment, because a resend renews the wait exactly as the first send opened it.
     *
     * @param string $identifier Normalized identifier this session is waiting on
     * @throws ItemNotFoundForUpdateException When the session is not persisted (id is null)
     * @throws HilosException On database error
     */
    public function holdPendingRegistration(string $identifier): void
    {
        $this->ensureCanWrite();

        if ($this->object->id === null) {
            throw new ItemNotFoundForUpdateException('Session not found for holdPendingRegistration (id is null)');
        }

        $this->object->pendingRegistrationIdentifier = $identifier;
        $this->object->pendingRegistrationSince = TimeHelper::getSqlDateTime();
        $this->object->sync();
    }

    /**
     * Forgets what this session was waiting on (HIL-612).
     *
     * The end of a flow as this session experienced it - its code came back, the person
     * said it was the wrong address, or a login made the half-finished registration moot.
     * It says nothing about the address itself: another session may still be waiting on
     * the same one, and the hold on it is released elsewhere or not at all.
     *
     * @throws ItemNotFoundForUpdateException When the session is not persisted (id is null)
     * @throws HilosException On database error
     */
    public function releasePendingRegistration(): void
    {
        $this->ensureCanWrite();

        if ($this->object->id === null) {
            throw new ItemNotFoundForUpdateException('Session not found for releasePendingRegistration (id is null)');
        }

        $this->object->pendingRegistrationIdentifier = null;
        $this->object->pendingRegistrationSince = null;
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
